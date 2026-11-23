<?php

namespace App\Billing;

use App\Generations\Quality;
use App\Generations\Size;
use App\Pixelforge\PriceSheet;

/**
 * Every price calculation in the app. All amounts are integer nanodollars.
 *
 * Rates are nanodollars per 1M tokens, so a token type costs
 * tokens × rate / 1,000,000. Users pay Pixelforge's cost plus 25%.
 */
final class CostCalculator
{
    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    private const BASIS_POINTS = 10_000;

    /**
     * What Pixelforge charges for a finished generation.
     *
     * @param  array<string, int>  $rates  Nanodollars per 1M tokens (see PriceSheet::parseRates).
     * @param  array<string, int>  $usage  Pixelforge's final token counts.
     */
    public static function providerCost(array $rates, array $usage): int
    {
        $tokens = [];

        foreach (PriceSheet::TOKEN_TYPES as $type) {
            $tokens[$type] = (int) ($usage[$type] ?? 0);
        }

        return self::costOf($rates, $tokens);
    }

    /**
     * The user charge: provider cost × 1.25, rounded up to a whole nanodollar.
     *
     * With today's rates every provider cost is a multiple of 4 nanodollars,
     * so this is exact; the rounding only guards future rates.
     */
    public static function withMarkup(int $providerCost): int
    {
        return intdiv($providerCost * 5 + 3, 4);
    }

    /**
     * The fewest and most a generation can cost the user, before it runs.
     */
    public static function estimate(PriceSheet $sheet, string $prompt, Size $size, Quality $quality, int $referenceCount): Estimate
    {
        $baseTextTokens = self::ceilDiv(self::characterCount($prompt), $sheet->charactersPerTextToken);
        $variance = $sheet->textTokenVarianceBasisPoints;
        $reasoning = $sheet->reasoningTokensFor($quality);
        $imageTokens = $sheet->tokensPerReferenceImage * $referenceCount;
        $outputTokens = $sheet->outputTokensFor($quality, $size);

        $min = [
            'input_text_tokens' => intdiv($baseTextTokens * (self::BASIS_POINTS - $variance), self::BASIS_POINTS),
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['min'],
        ];

        $max = [
            'input_text_tokens' => self::ceilDiv($baseTextTokens * (self::BASIS_POINTS + $variance), self::BASIS_POINTS),
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['max'],
        ];

        $lines = [];

        foreach (PriceSheet::TOKEN_TYPES as $type) {
            $lines[] = [
                'type' => $type,
                'min_tokens' => $min[$type],
                'max_tokens' => $max[$type],
                'min_nanos' => self::costOf($sheet->rates, [$type => $min[$type]]),
                'max_nanos' => self::costOf($sheet->rates, [$type => $max[$type]]),
            ];
        }

        $providerMin = self::costOf($sheet->rates, $min);
        $providerMax = self::costOf($sheet->rates, $max);

        return new Estimate(
            minNanos: self::withMarkup($providerMin),
            maxNanos: self::withMarkup($providerMax),
            providerMinNanos: $providerMin,
            providerMaxNanos: $providerMax,
            lines: $lines,
        );
    }

    /**
     * Pixelforge counts Unicode characters, not bytes.
     */
    public static function characterCount(string $prompt): int
    {
        return mb_strlen($prompt, 'UTF-8');
    }

    /**
     * Sum every token type into one numerator and divide once, rounding up
     * if a future rate ever leaves a fraction of a nanodollar.
     *
     * @param  array<string, int>  $rates
     * @param  array<string, int>  $tokens
     */
    private static function costOf(array $rates, array $tokens): int
    {
        $numerator = 0;

        foreach ($tokens as $type => $count) {
            $numerator += $count * $rates[$type];
        }

        return self::ceilDiv($numerator, self::TOKENS_PER_RATE_UNIT);
    }

    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
