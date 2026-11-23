<?php

use App\Billing\CostCalculator;
use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PriceSheet;
use App\Pixelforge\PricingRepository;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $this->travelTo('2026-11-23 15:00:00');
});

it('seeds demo users with history in the 29 days before today', function () {
    $this->seed(DemoSeeder::class);

    $generations = Generation::all();

    expect(User::where('email', 'like', '%@'.DemoSeeder::EMAIL_DOMAIN)->count())->toBe(3)
        ->and($generations)->not->toBeEmpty()
        ->and($generations->min('created_at')->gte('2026-10-25 00:00:00'))->toBeTrue()
        ->and($generations->max('completed_at')->lt('2026-11-23 00:00:00'))->toBeTrue()
        ->and($generations->pluck('status')->unique()->values()->all())->toEqualCanonicalizing([GenerationStatus::Succeeded, GenerationStatus::Failed]);
});

it('prices every demo generation the way real ones are priced', function () {
    $this->seed(DemoSeeder::class);

    foreach (Generation::where('status', GenerationStatus::Succeeded)->get() as $generation) {
        $provider = CostCalculator::providerCost(PriceSheet::parseRates($generation->rates), $generation->usage);

        expect($generation->provider_cost_nanos)->toBe($provider)
            ->and($generation->charge_nanos)->toBe(CostCalculator::withMarkup($provider))
            ->and($generation->charge_nanos)->toBeGreaterThanOrEqual($generation->estimate_min_nanos)
            ->and($generation->charge_nanos)->toBeLessThanOrEqual($generation->estimate_max_nanos)
            ->and(count($generation->reference_images) * 765)->toBe($generation->usage['input_image_tokens']);
    }

    expect(Generation::where('status', GenerationStatus::Failed)->where('charge_nanos', '!=', 0)->count())->toBe(0);
});

it('replaces its own data when run again and leaves other users alone', function () {
    $real = Generation::factory()->succeeded()->create();

    $this->seed(DemoSeeder::class);
    $first = Generation::count();
    $this->seed(DemoSeeder::class);

    expect(Generation::count())->toBe($first)
        ->and($real->fresh())->not->toBeNull();
});

it('refuses to run in production', function () {
    $this->app['env'] = 'production';

    app(DemoSeeder::class)->run();
})->throws(RuntimeException::class, 'never runs in production');
