<?php

namespace App\Console\Commands;

use App\Enums\UpdateSource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\GenerationStatusUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * The fallback for webhooks that never arrive and submission jobs that were lost.
 *
 * Runs every minute. Polls at most 20 generations per run, leaving 40 of
 * Pixelforge's 60 requests a minute for new submissions.
 */
#[Signature('generations:reconcile')]
#[Description('Poll Pixelforge for generations with no recent update and resend lost submissions')]
class ReconcileGenerations extends Command
{
    public const int POLLS_PER_RUN = 20;

    private const int STALE_AFTER_MINUTES = 2;

    public function handle(PixelforgeClient $client, GenerationStatusUpdater $updater): int
    {
        $polled = $this->pollStaleGenerations($client, $updater);
        $resent = $this->resendLostSubmissions();

        $this->info("Polled {$polled} generation(s), resent {$resent} submission(s).");

        return self::SUCCESS;
    }

    /**
     * Queued or processing with no status change for 2 minutes, each polled at most once a minute.
     */
    private function pollStaleGenerations(PixelforgeClient $client, GenerationStatusUpdater $updater): int
    {
        $generations = Generation::query()
            ->inFlight()
            ->whereNotNull('pixelforge_id')
            ->where('status_changed_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->where(fn ($query) => $query
                ->whereNull('last_polled_at')
                ->orWhere('last_polled_at', '<=', now()->subMinute()))
            ->orderByRaw('last_polled_at asc nulls first')
            ->limit(self::POLLS_PER_RUN)
            ->get();

        foreach ($generations as $generation) {
            Generation::query()->whereKey($generation->id)->update(['last_polled_at' => now()]);

            try {
                $object = $client->getGeneration($generation->pixelforge_id, $generation->id);
            } catch (ConnectionException|RequestException) {
                continue;
            }

            $updater->apply($generation, $object, UpdateSource::Poll);
        }

        return $generations->count();
    }

    /**
     * A submission whose next attempt was due over 2 minutes ago has lost its
     * job (for example the worker restarted). Dispatching again is safe: it
     * reuses the same idempotency key, and a queued copy blocks a second one.
     */
    private function resendLostSubmissions(): int
    {
        $generations = Generation::query()
            ->submitting()
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        foreach ($generations as $generation) {
            Generation::query()->whereKey($generation->id)->update(['next_attempt_at' => now()]);
            SubmitGeneration::dispatch($generation);
        }

        return $generations->count();
    }
}
