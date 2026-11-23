<?php

namespace App\Jobs;

use App\Generations\FailureReason;
use App\Generations\GenerationStatus;
use App\Generations\GenerationStatusUpdater;
use App\Generations\PendingReason;
use App\Models\Generation;
use App\Pixelforge\CreateOutcome;
use App\Pixelforge\CreateResult;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Send one generation to Pixelforge, retrying by dispatching itself again.
 *
 * Every attempt sends the generation's own idempotency key, so no retry can
 * create or bill a second Pixelforge generation. The retry policy lives in
 * this class rather than in queue settings, so it can be read in one place.
 */
class SubmitGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** 503 overloaded and 429 rate limit responses allowed before giving up. */
    public const MAX_ATTEMPTS = 5;

    /** How long to wait for one of Pixelforge's concurrent slots before giving up. */
    public const SLOT_WAIT_MINUTES = 10;

    /** Pixelforge allows 3 generations queued or processing at once. */
    public const CONCURRENT_GENERATIONS = 3;

    private const SLOT_RETRY_SECONDS = 5;

    private const MAX_BACKOFF_SECONDS = 60;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->generation->id))->dontRelease()];
    }

    public function handle(PixelforgeClient $client, GenerationStatusUpdater $updater): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Submitting) {
            return;
        }

        if ($this->allSlotsBusy()) {
            $this->waitForSlot($generation, $updater, self::SLOT_RETRY_SECONDS);

            return;
        }

        $result = $client->createGeneration([
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
        ], $generation->idempotency_key, $generation->id);

        match ($result->outcome) {
            CreateOutcome::Accepted => $updater->apply($generation, $result->generation, 'create'),
            CreateOutcome::Retryable => $this->retryAfterRejection($generation, $updater, $result),
            CreateOutcome::NoSlot => $this->waitForSlot($generation, $updater, $result->retryAfterSeconds ?? self::SLOT_RETRY_SECONDS),
            CreateOutcome::Final => $updater->failAtSubmission($generation, $result->errorCode ?? 'unknown_error', $result->errorMessage),
            CreateOutcome::Unknown => $this->retryUnreachable($generation),
        };
    }

    /**
     * Checking locally saves requests that would only get concurrency_limit_exceeded.
     */
    private function allSlotsBusy(): bool
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count() >= self::CONCURRENT_GENERATIONS;
    }

    /**
     * 503 overloaded or 429 rate limit: nothing was created, so after 5 tries
     * it is safe to fail at $0.
     */
    private function retryAfterRejection(Generation $generation, GenerationStatusUpdater $updater, CreateResult $result): void
    {
        $attempts = $generation->submit_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $updater->failAtSubmission($generation, $result->errorCode ?? 'overloaded', $result->errorMessage);

            return;
        }

        $backoff = max($result->retryAfterSeconds ?? 0, 2 ** $attempts) + random_int(0, 1);

        $this->retryLater($generation, min($backoff, self::MAX_BACKOFF_SECONDS), [
            'submit_attempts' => $attempts,
        ]);
    }

    /**
     * Waiting for a slot does not count toward the 5 attempts.
     */
    private function waitForSlot(Generation $generation, GenerationStatusUpdater $updater, int $seconds): void
    {
        $waitingSince = $generation->waiting_since ?? now();

        if ($waitingSince->lte(now()->subMinutes(self::SLOT_WAIT_MINUTES))) {
            $updater->failAtSubmission($generation, FailureReason::CONCURRENCY_TIMEOUT);

            return;
        }

        $this->retryLater($generation, $seconds, [
            'pending_reason' => PendingReason::WaitingForSlot,
            'waiting_since' => $waitingSince,
        ]);
    }

    /**
     * A timeout or unexpected 5xx: Pixelforge may have created the generation,
     * so never fail it. A later attempt with the same key either returns what
     * Pixelforge made or creates it now.
     */
    private function retryUnreachable(Generation $generation): void
    {
        $attempts = $generation->unreachable_attempts + 1;

        $this->retryLater($generation, min(5 * 2 ** ($attempts - 1), self::MAX_BACKOFF_SECONDS), [
            'pending_reason' => PendingReason::Unreachable,
            'unreachable_attempts' => $attempts,
        ]);
    }

    /**
     * Record the retry only while the generation is still submitting, so a
     * webhook that has already moved it on is never overwritten.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function retryLater(Generation $generation, int $seconds, array $attributes): void
    {
        $updated = Generation::query()
            ->whereKey($generation->id)
            ->where('status', GenerationStatus::Submitting)
            ->update([...$attributes, 'next_attempt_at' => now()->addSeconds($seconds)]);

        if ($updated === 1) {
            self::dispatch($generation)->delay($seconds);
        }
    }
}
