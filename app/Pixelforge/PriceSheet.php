<?php

namespace App\Pixelforge;

use App\Billing\Nanos;
use App\Generations\Quality;
use App\Generations\Size;

/**
 * Pixelforge's published pricing (GET /v1/pricing), converted to integers once
 * so every calculation after this is integer math.
 */
final readonly class PriceSheet
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rateStrings  Rates as Pixelforge sent them, in dollars per 1M tokens.
     * @param  array<string, int>  $rates  Nanodollars per 1M tokens, keyed like $rateStrings.
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens.
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range.
     */
    public function __construct(
        public array $rateStrings,
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $body  The decoded GET /v1/pricing response.
     */
    public static function fromPricingResponse(array $body): self
    {
        $tokens = $body['tokens'];

        return new self(
            rateStrings: $body['rates'],
            rates: self::parseRates($body['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVarianceBasisPoints: (int) round($tokens['text_token_variance'] * 10_000),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * Parse a rates object (from /v1/pricing or a generation's frozen rates)
     * into nanodollars per 1M tokens.
     *
     * @param  array<string, string>  $rates
     * @return array<string, int>
     */
    public static function parseRates(array $rates): array
    {
        $parsed = [];

        foreach (self::TOKEN_TYPES as $type) {
            $parsed[$type] = Nanos::fromDecimal((string) $rates[$type]);
        }

        return $parsed;
    }

    public function outputTokensFor(Quality $quality, Size $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokensFor(Quality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value];

        return ['min' => (int) $range['min'], 'max' => (int) $range['max']];
    }

    /**
     * The integer form the generate page uses for its live estimate.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_basis_points' => $this->textTokenVarianceBasisPoints,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
