<?php

use App\Generations\GenerationStatus;
use App\Generations\GenerationStatusUpdater;
use App\Generations\PendingReason;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const SUBMIT_URL = 'https://pixelforge.promptedit.dev/v1/generations';

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pf_test_key']);
    Http::preventStrayRequests();
});

/**
 * Run the job the way the queue worker would: release its unique lock as
 * processing starts (ShouldBeUniqueUntilProcessing), then handle it without
 * its middleware.
 */
function runSubmission(Generation $generation): Generation
{
    $job = new SubmitGeneration($generation);
    (new UniqueLock(Cache::driver()))->release($job);
    $job->handle(app(PixelforgeClient::class), app(GenerationStatusUpdater::class));

    return $generation->fresh();
}

it('retries a 503 with the same idempotency key and is accepted', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => Http::sequence()
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503, ['Retry-After' => '2'])
        ->push(pixelforgeGeneration(), 202)]);
    $generation = Generation::factory()->create(['prompt' => 'A lighthouse at dawn']);

    $afterFirst = runSubmission($generation);
    $afterSecond = runSubmission($generation);

    expect($afterFirst->status)->toBe(GenerationStatus::Submitting)
        ->and($afterFirst->submit_attempts)->toBe(1)
        ->and($afterSecond->status)->toBe(GenerationStatus::Queued)
        ->and($afterSecond->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation) && $job->delay >= 2 && $job->delay <= 3);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key') === [$generation->idempotency_key]
        && $request['prompt'] === 'A lighthouse at dawn'
        && $request['metadata'] === ['app_generation_id' => $generation->id]);
    Http::assertNotSent(fn (Request $request) => $request->header('Idempotency-Key') !== [$generation->idempotency_key]);
});

it('gives up after 5 overloaded responses and charges nothing', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => pixelforgeError(503, 'overloaded', ['Retry-After' => '2'])]);
    $generation = Generation::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $result = runSubmission($generation);
    }

    expect($result->status)->toBe(GenerationStatus::Failed)
        ->and($result->failure_code)->toBe('overloaded')
        ->and($result->charge_nanos)->toBe(0);
    Http::assertSentCount(5);
    Queue::assertPushed(SubmitGeneration::class, 4);
});

it('waits for a free slot, then fails after 10 minutes', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => pixelforgeError(429, 'concurrency_limit_exceeded', ['Retry-After' => '5'])]);
    $generation = Generation::factory()->create();

    $waiting = runSubmission($generation);
    $this->travel(11)->minutes();
    $timedOut = runSubmission($generation);

    expect($waiting->status)->toBe(GenerationStatus::Submitting)
        ->and($waiting->pending_reason)->toBe(PendingReason::WaitingForSlot)
        ->and($waiting->submit_attempts)->toBe(0)
        ->and($timedOut->status)->toBe(GenerationStatus::Failed)
        ->and($timedOut->failure_code)->toBe('concurrency_timeout')
        ->and($timedOut->charge_nanos)->toBe(0);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->delay === 5);
});

it('waits without calling Pixelforge when 3 generations are already in flight', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => Http::response(pixelforgeGeneration(), 202)]);
    Generation::factory()->queued()->count(2)->create();
    Generation::factory()->processing()->create();
    $generation = Generation::factory()->create();

    $result = runSubmission($generation);

    expect($result->status)->toBe(GenerationStatus::Submitting)
        ->and($result->pending_reason)->toBe(PendingReason::WaitingForSlot);
    Http::assertNothingSent();
    Queue::assertPushed(SubmitGeneration::class, 1);
});

it('fails at once on a rejection retrying cannot fix', function (int $status, string $code) {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => pixelforgeError($status, $code)]);
    $generation = Generation::factory()->create();

    $result = runSubmission($generation);

    expect($result->status)->toBe(GenerationStatus::Failed)
        ->and($result->failure_code)->toBe($code)
        ->and($result->charge_nanos)->toBe(0);
    Http::assertSentCount(1);
    Queue::assertNotPushed(SubmitGeneration::class);
})->with([
    'blocked prompt' => [400, 'content_policy_violation'],
    'bad API key' => [401, 'invalid_api_key'],
    'key reused' => [409, 'idempotency_key_reused'],
    'validation' => [422, 'validation_failed'],
    'daily limit' => [429, 'daily_limit_exceeded'],
]);

it('keeps retrying after a timeout and charges once when the replay shows it was created', function () {
    Queue::fake([SubmitGeneration::class]);
    $calls = 0;
    Http::fake([SUBMIT_URL => function () use (&$calls) {
        $calls++;

        if ($calls === 1) {
            throw new ConnectionException('Operation timed out');
        }

        return Http::response(pixelforgeSucceededGeneration(), 202, ['Idempotent-Replayed' => 'true']);
    }]);
    $generation = Generation::factory()->create();

    $unreachable = runSubmission($generation);
    $replayed = runSubmission($generation);
    $again = runSubmission($generation);

    expect($unreachable->status)->toBe(GenerationStatus::Submitting)
        ->and($unreachable->pending_reason)->toBe(PendingReason::Unreachable)
        ->and($unreachable->unreachable_attempts)->toBe(1)
        ->and($replayed->status)->toBe(GenerationStatus::Succeeded)
        ->and($replayed->pending_reason)->toBeNull()
        ->and($replayed->charge_nanos)->toBe(381_887_500)
        ->and($again->charge_nanos)->toBe(381_887_500);
    expect($calls)->toBe(2);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->delay === 5);
});

it('never fails on an unexpected 5xx', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake([SUBMIT_URL => pixelforgeError(502, 'bad_gateway')]);
    $generation = Generation::factory()->create(['unreachable_attempts' => 9]);

    $result = runSubmission($generation);

    expect($result->status)->toBe(GenerationStatus::Submitting)
        ->and($result->pending_reason)->toBe(PendingReason::Unreachable);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->delay === 60);
});

it('does not send a generation a webhook has already moved on', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake();
    $generation = Generation::factory()->processing()->create();

    runSubmission($generation);

    Http::assertNothingSent();
    Queue::assertNotPushed(SubmitGeneration::class);
});
