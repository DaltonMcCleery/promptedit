<?php

use App\Billing\Nanos;

it('parses Pixelforge amounts into nanodollars', function (string $decimal, int $nanos) {
    expect(Nanos::fromDecimal($decimal))->toBe($nanos);
})->with([
    'worked example' => ['0.373875000', 373_875_000],
    'usage cost' => ['0.169080000', 169_080_000],
    'zero' => ['0.000000000', 0],
    'one nanodollar' => ['0.000000001', 1],
    'rate with cents' => ['12.50', 12_500_000_000],
    'whole rate' => ['5', 5_000_000_000],
    'large amount' => ['123.456789012', 123_456_789_012],
]);

it('formats nanodollars as an exact 9-decimal string', function (int $nanos, string $decimal) {
    expect(Nanos::toDecimal($nanos))->toBe($decimal);
})->with([
    [373_875_000, '0.373875000'],
    [467_343_750, '0.467343750'],
    [0, '0.000000000'],
    [1, '0.000000001'],
    [5_000_000_000, '5.000000000'],
    [-575_000, '-0.000575000'],
]);

it('round trips through both conversions', function () {
    expect(Nanos::toDecimal(Nanos::fromDecimal('0.046125000')))->toBe('0.046125000');
});

it('rejects anything that is not a plain decimal with at most 9 places', function (string $value) {
    Nanos::fromDecimal($value);
})->throws(InvalidArgumentException::class)->with([
    'ten decimal places' => ['0.0000000001'],
    'letters' => ['abc'],
    'negative' => ['-1.00'],
    'empty' => [''],
    'exponent' => ['1e-9'],
    'trailing dot' => ['1.'],
]);
