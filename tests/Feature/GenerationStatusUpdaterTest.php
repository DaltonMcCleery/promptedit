<?php

use App\Billing\CostCalculator;
use App\Generations\GenerationStatus;
use App\Generations\GenerationStatusUpdater;
use App\Generations\PendingReason;
use App\Models\Generation;
use App\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;

function updater(): GenerationStatusUpdater
{
    return app(GenerationStatusUpdater::class);
}

it('moves forward through queued and processing to succeeded and records the exact cost', function () {
    $generation = Generation::factory()->create(['pending_reason' => PendingReason::Unreachable]);

    updater()->apply($generation, pixelforgeGeneration(), 'create');
    updater()->apply($generation, pixelforgeGeneration(['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z']), 'webhook');
    $result = updater()->apply($generation, pixelforgeSucceededGeneration(), 'webhook');

    expect($result->status)->toBe(GenerationStatus::Succeeded)
        ->and($result->pending_reason)->toBeNull()
        ->and($result->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($result->usage['reasoning_tokens'])->toBe(3184)
        ->and($result->provider_cost_nanos)->toBe(305_510_000)
        ->and($result->charge_nanos)->toBe(381_887_500)
        ->and($result->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($result->image_url_expires_at->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($result->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
});

it('takes the completion time from Pixelforge, not the clock', function () {
    $this->travelTo('2026-11-05 00:00:03');
    $generation = Generation::factory()->processing()->create();

    $result = updater()->apply($generation, pixelforgeSucceededGeneration(['completed_at' => '2026-11-04T23:59:58Z']), 'webhook');

    expect($result->completed_at->toIso8601ZuluString())->toBe('2026-11-04T23:59:58Z');
});

it('ignores a processing event that arrives after succeeded', function () {
    $generation = Generation::factory()->processing()->create();
    updater()->apply($generation, pixelforgeSucceededGeneration(), 'webhook');

    $result = updater()->apply($generation, pixelforgeGeneration(['status' => 'processing']), 'webhook');

    expect($result->status)->toBe(GenerationStatus::Succeeded)
        ->and($result->charge_nanos)->toBe(381_887_500);
});

it('charges once when succeeded is reported twice with different usage', function () {
    $generation = Generation::factory()->processing()->create();
    updater()->apply($generation, pixelforgeSucceededGeneration(), 'webhook');

    $result = updater()->apply($generation, pixelforgeSucceededGeneration([
        'usage' => ['input_text_tokens' => 99, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 101],
    ]), 'poll');

    expect($result->charge_nanos)->toBe(381_887_500)
        ->and($result->usage['input_text_tokens'])->toBe(12);
});

it('never moves between the two final states', function () {
    $generation = Generation::factory()->processing()->create();
    updater()->apply($generation, pixelforgeGeneration(['status' => 'failed', 'error' => ['code' => 'internal_error', 'message' => 'Oops.']]), 'webhook');

    $result = updater()->apply($generation, pixelforgeSucceededGeneration(), 'webhook');

    expect($result->status)->toBe(GenerationStatus::Failed)
        ->and($result->charge_nanos)->toBe(0);
});

it('records a failure after acceptance at $0 with its reason', function () {
    $generation = Generation::factory()->queued()->create();

    $result = updater()->apply($generation, pixelforgeGeneration([
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => '2026-11-04T14:03:20Z',
    ]), 'webhook');

    expect($result->status)->toBe(GenerationStatus::Failed)
        ->and($result->failure_code)->toBe('generation_failed')
        ->and($result->failure_message)->toBe('The model could not produce an image.')
        ->and($result->provider_cost_nanos)->toBe(0)
        ->and($result->charge_nanos)->toBe(0)
        ->and($result->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:20Z');
});

it('accepts a generation that is already finished when it is first reported', function () {
    $generation = Generation::factory()->create();

    $result = updater()->apply($generation, pixelforgeSucceededGeneration(), 'create');

    expect($result->status)->toBe(GenerationStatus::Succeeded)
        ->and($result->rates)->toBe(pixelforgePricing()['rates'])
        ->and($result->charge_nanos)->toBe(381_887_500);
});

it('records the Pixelforge id and rates from whichever update arrives first', function () {
    $generation = Generation::factory()->create();

    $result = updater()->apply($generation, pixelforgeGeneration(['status' => 'processing']), 'webhook');

    expect($result->pixelforge_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($result->rates)->toBe(pixelforgePricing()['rates']);
});

it('keeps the estimate unchanged when the frozen rates match', function () {
    $generation = Generation::factory()->create();

    $result = updater()->apply($generation, pixelforgeSucceededGeneration(), 'webhook');

    expect($result->estimate_min_nanos)->toBe(58_175_000)
        ->and($result->estimate_max_nanos)->toBe(86_325_000);
});

it('re-estimates at the frozen rates when Pixelforge changed its prices', function () {
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $generation = Generation::factory()->create(['prompt' => str_repeat('a', 40)]);
    $newRates = array_replace(pixelforgePricing()['rates'], ['output_tokens' => '50.00']);

    $result = updater()->apply($generation, pixelforgeGeneration(['rates' => $newRates]), 'create');

    $expected = CostCalculator::estimate(docsPriceSheet()->withRates($newRates), $generation->prompt, $generation->size, $generation->quality, 0);

    expect($result->estimate_max_nanos)->toBe($expected->maxNanos)
        ->and($result->estimate_max_nanos)->toBeGreaterThan(86_325_000)
        ->and($result->estimate_rates)->toBe($newRates);
});

it('ignores an object with an unknown status', function () {
    $generation = Generation::factory()->queued()->create();

    $result = updater()->apply($generation, pixelforgeGeneration(['status' => 'exploded']), 'webhook');

    expect($result->status)->toBe(GenerationStatus::Queued);
});

it('fails a submission that Pixelforge rejected, at $0', function () {
    $this->freezeTime();
    $generation = Generation::factory()->create(['pending_reason' => PendingReason::WaitingForSlot]);

    $result = updater()->failAtSubmission($generation, 'content_policy_violation', 'The prompt was rejected.');

    expect($result->status)->toBe(GenerationStatus::Failed)
        ->and($result->pending_reason)->toBeNull()
        ->and($result->failure_code)->toBe('content_policy_violation')
        ->and($result->charge_nanos)->toBe(0)
        ->and($result->provider_cost_nanos)->toBe(0)
        ->and($result->completed_at->toIso8601ZuluString())->toBe(now()->toIso8601ZuluString());
});

it('does not fail a submission that Pixelforge already accepted', function () {
    $generation = Generation::factory()->queued()->create();

    $result = updater()->failAtSubmission($generation, 'overloaded');

    expect($result->status)->toBe(GenerationStatus::Queued)
        ->and($result->failure_code)->toBeNull();
});
