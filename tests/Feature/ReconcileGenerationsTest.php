<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pf_test_key']);
    Http::preventStrayRequests();
    $this->freezeTime();
});

it('polls only generations with no status change for 2 minutes', function () {
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations/*' => Http::response(pixelforgeGeneration(['status' => 'processing']))]);
    $stale = Generation::factory()->queued()->create(['status_changed_at' => now()->subMinutes(3)]);
    Generation::factory()->queued()->create(['status_changed_at' => now()->subMinute()]);
    Generation::factory()->succeeded()->create(['status_changed_at' => now()->subHour()]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === "https://pixelforge.promptedit.dev/v1/generations/{$stale->pixelforge_id}");
    expect($stale->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($stale->fresh()->last_polled_at)->not->toBeNull();
});

it('skips a generation polled less than a minute ago', function () {
    Http::fake();
    Generation::factory()->processing()->create([
        'status_changed_at' => now()->subMinutes(5),
        'last_polled_at' => now()->subSeconds(30),
    ]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('polls at most 20 generations per run', function () {
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations/*' => Http::response(pixelforgeGeneration(['status' => 'processing']))]);
    Generation::factory()->processing()->count(25)->create(['status_changed_at' => now()->subMinutes(3)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSentCount(20);
});

it('charges a polled success once, through the same rules as webhooks', function () {
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations/*' => Http::response(pixelforgeSucceededGeneration())]);
    $generation = Generation::factory()->processing()->create(['status_changed_at' => now()->subMinutes(3)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanos->toBe(381_887_500);
});

it('carries on when one poll fails', function () {
    $broken = Generation::factory()->processing()->create(['status_changed_at' => now()->subMinutes(4)]);
    $healthy = Generation::factory()->processing()->create(['status_changed_at' => now()->subMinutes(3)]);
    Http::fake([
        "https://pixelforge.promptedit.dev/v1/generations/{$broken->pixelforge_id}" => pixelforgeError(503, 'overloaded'),
        "https://pixelforge.promptedit.dev/v1/generations/{$healthy->pixelforge_id}" => Http::response(pixelforgeSucceededGeneration()),
    ]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($broken->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($healthy->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('resends a submission whose job was lost, with its original key', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake();
    $lost = Generation::factory()->create(['next_attempt_at' => now()->subMinutes(3)]);
    $key = $lost->idempotency_key;

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($lost));
    expect($lost->fresh()->idempotency_key)->toBe($key)
        ->and($lost->fresh()->next_attempt_at->toIso8601ZuluString())->toBe(now()->toIso8601ZuluString());
});

it('leaves a submission alone while its next attempt is still on time', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake();
    Generation::factory()->create(['next_attempt_at' => now()->subSeconds(30)]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertNotPushed(SubmitGeneration::class);
});

it('is scheduled every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('generations:reconcile')->assertSuccessful();
});
