<?php

use App\Services\Pixelforge\PriceSheet;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Http;

const PRICING_URL = 'https://pixelforge.promptedit.dev/v1/pricing';

beforeEach(function () {
    Http::preventStrayRequests();
});

it('reads pricing from Pixelforge and caches it for 10 minutes', function () {
    Http::fake([PRICING_URL => Http::response(pixelforgePricing())]);

    $first = app(PricingRepository::class)->current();
    $this->travel(9)->minutes();
    app(PricingRepository::class)->current();

    expect($first)->toBeInstanceOf(PriceSheet::class)
        ->and($first->rates['output_tokens'])->toBe(40_000_000_000);
    Http::assertSentCount(1);
});

it('fetches again once the cache expires', function () {
    Http::fake([PRICING_URL => Http::response(pixelforgePricing())]);

    app(PricingRepository::class)->current();
    $this->travel(11)->minutes();
    app(PricingRepository::class)->current();

    Http::assertSentCount(2);
});

it('falls back to the last pricing it fetched when Pixelforge is down', function () {
    Http::fake([PRICING_URL => Http::sequence()
        ->push(pixelforgePricing())
        ->push(['error' => ['code' => 'overloaded']], 503)]);

    app(PricingRepository::class)->current();
    $this->travel(11)->minutes();

    expect(app(PricingRepository::class)->current()?->rates['reasoning_tokens'])->toBe(12_500_000_000);
});

it('returns null when pricing has never been fetched and Pixelforge is down', function () {
    Http::fake([PRICING_URL => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    expect(app(PricingRepository::class)->current())->toBeNull();
});
