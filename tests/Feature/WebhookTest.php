<?php

use App\Generations\GenerationStatus;
use App\Generations\GenerationStatusUpdater;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

it('applies a signed succeeded event and charges the exact cost', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration()))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanos->toBe(381_887_500)
        ->and(WebhookEvent::sole()->generation_id)->toBe($generation->id);
});

it('does nothing with an event it has already received', function () {
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $event = pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration());
    $this->mock(GenerationStatusUpdater::class)->shouldReceive('apply')->once();

    sendPixelforgeWebhook($event)->assertNoContent();
    sendPixelforgeWebhook($event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::count())->toBe(1);
});

it('charges once when the same generation succeeds in two different events', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration(), 'evt_1'));
    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration([
        'usage' => ['input_text_tokens' => 999, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 1001],
    ]), 'evt_2'));

    expect($generation->fresh()->charge_nanos)->toBe(381_887_500);
});

it('ignores a processing event delivered after succeeded', function () {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration(), 'evt_succeeded'));
    sendPixelforgeWebhook(pixelforgeEvent('generation.processing', pixelforgeGeneration(['status' => 'processing']), 'evt_processing'))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanos->toBe(381_887_500);
});

it('finds the generation by its metadata when the webhook beats the create response', function () {
    $generation = Generation::factory()->create();

    sendPixelforgeWebhook(pixelforgeEvent('generation.processing', pixelforgeGeneration([
        'status' => 'processing',
        'metadata' => ['app_generation_id' => $generation->id],
    ])))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Processing)
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->rates->toEqual(pixelforgePricing()['rates']);
});

it('records a failed event at $0', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.failed', pixelforgeGeneration([
        'status' => 'failed',
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
        'completed_at' => '2026-11-04T14:03:29Z',
    ])))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->failure_code->toBe('internal_error')
        ->charge_nanos->toBe(0);
});

it('acknowledges an event for an unknown generation so Pixelforge stops retrying', function () {
    Log::spy();

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration(['id' => 'gen_unknown'])))
        ->assertOk()
        ->assertJson(['status' => 'unknown_generation']);

    expect(WebhookEvent::sole()->generation_id)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('acknowledges a ping and changes nothing else', function () {
    $generation = Generation::factory()->queued()->create();

    sendPixelforgeWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])
        ->assertOk()
        ->assertJson(['status' => 'ping']);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects a request with a bad signature and stores nothing', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration()), secret: 'whsec_forged')
        ->assertBadRequest();

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects a replayed request whose timestamp is too old', function () {
    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration()), timestamp: time() - 301)
        ->assertBadRequest();

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a signed body that is not an event', function () {
    $this->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign('not json', 'whsec_test', time()),
    ], content: 'not json')->assertBadRequest();
});

it('rolls back and answers 500 when applying the event fails, so Pixelforge retries', function () {
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $this->mock(GenerationStatusUpdater::class)->shouldReceive('apply')->andThrow(new RuntimeException('Database went away'));

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration()))->assertServerError();

    expect(WebhookEvent::count())->toBe(0);
});

it('does not start a session or set cookies', function () {
    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeSucceededGeneration(['id' => 'gen_unknown'])))
        ->assertOk()
        ->assertHeaderMissing('Set-Cookie');
});
