<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Money is stored as whole nanodollars (1 USD = 1,000,000,000), the smallest
 * unit Pixelforge reports. These helpers convert to and from Pixelforge's
 * decimal strings without ever touching a float.
 */
final class Nanos
{
    public const PER_DOLLAR = 1_000_000_000;

    private const DECIMALS = 9;

    /**
     * Parse a non-negative decimal string such as "0.373875000" or a rate
     * such as "12.50" into nanodollars.
     *
     * A rate in dollars per 1M tokens parses to nanodollars per 1M tokens.
     */
    public static function fromDecimal(string $value): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,9}))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Not a decimal amount with at most 9 decimal places: [{$value}].");
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', self::DECIMALS, '0');

        return $whole * self::PER_DOLLAR + $fraction;
    }

    /**
     * Format nanodollars as an exact 9-decimal string, e.g. 373875000 → "0.373875000".
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $absolute = abs($nanos);

        $whole = intdiv($absolute, self::PER_DOLLAR);
        $fraction = str_pad((string) ($absolute % self::PER_DOLLAR), self::DECIMALS, '0', STR_PAD_LEFT);

        return "{$sign}{$whole}.{$fraction}";
    }
}
