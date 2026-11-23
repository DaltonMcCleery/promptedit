<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostCalculator;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validGeneration(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ], $overrides);
}

it('redirects guests to sign in', function () {
    $this->post(route('generations.store'), validGeneration())->assertRedirect(route('login'));
});

it('saves the generation with a server-side estimate and queues the submission', function () {
    Queue::fake([SubmitGeneration::class]);
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), validGeneration(['estimate_max_nanos' => 1]))
        ->assertRedirect(route('generations.index'));

    $generation = $user->generations()->sole();
    $expected = CostCalculator::estimate(docsPriceSheet(), $generation->prompt, Size::Landscape, Quality::High, 1);

    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and($generation->idempotency_key)->toBeUuid()
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        ->and($generation->estimate_min_nanos)->toBe($expected->minNanos)
        ->and($generation->estimate_max_nanos)->toBe($expected->maxNanos)
        ->and($generation->estimate_rates)->toEqual(pixelforgePricing()['rates']);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('drops empty reference image fields', function () {
    Queue::fake([SubmitGeneration::class]);
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), validGeneration([
        'reference_images' => ['', 'https://example.com/a.png', null, '  '],
    ]))->assertSessionHasNoErrors();

    expect($user->generations()->sole()->reference_images)->toBe(['https://example.com/a.png']);
});

it('rejects invalid input and sends nothing', function (array $input, string $field) {
    Queue::fake([SubmitGeneration::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), validGeneration($input))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNotPushed(SubmitGeneration::class);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4,000 characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'ftp reference image' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('accepts a prompt of exactly 4,000 Unicode characters', function () {
    Queue::fake([SubmitGeneration::class]);
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), validGeneration(['prompt' => str_repeat('é', 4000)]))
        ->assertSessionHasNoErrors();
});

it('refuses the submission when prices are unavailable', function () {
    Queue::fake([SubmitGeneration::class]);
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => pixelforgeError(503, 'overloaded')]);

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), validGeneration())
        ->assertSessionHasErrors('pricing');

    expect(Generation::count())->toBe(0);
    Queue::assertNotPushed(SubmitGeneration::class);
});
