<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Services\Billing\CostCalculator;
use App\Services\Pixelforge\PriceSheet;

it('reproduces the docs worked example maximum exactly', function () {
    $estimate = CostCalculator::estimate(docsPriceSheet(), str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    expect($estimate->providerMaxNanos)->toBe(373_875_000)
        ->and($estimate->maxNanos)->toBe(467_343_750);

    $lines = collect($estimate->lines)->keyBy('type');

    expect($lines['input_text_tokens']['max_tokens'])->toBe(115)
        ->and($lines['input_text_tokens']['max_nanos'])->toBe(575_000)
        ->and($lines['input_image_tokens']['max_tokens'])->toBe(1_530)
        ->and($lines['input_image_tokens']['max_nanos'])->toBe(15_300_000)
        ->and($lines['output_tokens']['max_tokens'])->toBe(6_450)
        ->and($lines['output_tokens']['max_nanos'])->toBe(258_000_000)
        ->and($lines['reasoning_tokens']['max_tokens'])->toBe(8_000)
        ->and($lines['reasoning_tokens']['max_nanos'])->toBe(100_000_000);
});

it('computes the minimum from the fewest possible tokens', function () {
    $estimate = CostCalculator::estimate(docsPriceSheet(), str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    // 85 text, 1,530 image, 6,450 output and 1,000 reasoning tokens.
    expect($estimate->providerMinNanos)->toBe(286_225_000)
        ->and($estimate->minNanos)->toBe(357_781_250);
});

it('makes the breakdown lines plus markup add up to the totals', function () {
    $estimate = CostCalculator::estimate(docsPriceSheet(), 'A lighthouse on a cliff at dawn', Size::Square, Quality::Medium, 1);

    expect(array_sum(array_column($estimate->lines, 'min_nanos')))->toBe($estimate->providerMinNanos)
        ->and(array_sum(array_column($estimate->lines, 'max_nanos')))->toBe($estimate->providerMaxNanos)
        ->and($estimate->toArray()['markup_max_nanos'])->toBe($estimate->maxNanos - $estimate->providerMaxNanos);
});

it('counts Unicode characters, not bytes', function () {
    $prompt = '🎨🎨🎨🎨';

    expect(strlen($prompt))->toBe(16)
        ->and(CostCalculator::characterCount($prompt))->toBe(4);

    $lines = collect(CostCalculator::estimate(docsPriceSheet(), $prompt, Size::Square, Quality::Low, 0)->lines)->keyBy('type');

    // ceil(4 / 4) = 1 token, so 0 at the low end and 2 at the high end.
    expect($lines['input_text_tokens']['min_tokens'])->toBe(0)
        ->and($lines['input_text_tokens']['max_tokens'])->toBe(2);
});

it('prices finished usage at the given rates', function () {
    $rates = PriceSheet::parseRates(pixelforgePricing()['rates']);

    // The succeeded generation example from the docs.
    $cost = CostCalculator::providerCost($rates, [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6_450,
        'reasoning_tokens' => 3_184,
        'total_tokens' => 10_411,
    ]);

    expect($cost)->toBe(305_510_000)
        ->and(CostCalculator::withMarkup($cost))->toBe(381_887_500);
});

it('matches the cost_usd of the docs usage example', function () {
    $rates = PriceSheet::parseRates(pixelforgePricing()['rates']);

    $cost = CostCalculator::providerCost($rates, [
        'input_text_tokens' => 41,
        'input_image_tokens' => 0,
        'output_tokens' => 3_300,
        'reasoning_tokens' => 2_950,
    ]);

    expect($cost)->toBe(169_080_000);
});

it('adds exactly 25 percent and rounds any fraction up', function (int $providerCost, int $charge) {
    expect(CostCalculator::withMarkup($providerCost))->toBe($charge);
})->with([
    [0, 0],
    [4, 5],
    [1, 2],
    [2, 3],
    [3, 4],
    [373_875_000, 467_343_750],
]);

it('rounds a fraction of a nanodollar up', function () {
    // A hypothetical rate of $0.000001 per 1M tokens is 1 nanodollar per 1M tokens.
    $rates = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];

    expect(CostCalculator::providerCost($rates, ['input_text_tokens' => 10]))->toBe(1);
});
