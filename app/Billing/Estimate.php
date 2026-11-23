<?php

namespace App\Billing;

/**
 * The range a generation can cost the user, before it runs.
 *
 * Lines are Pixelforge's cost per token type; the markup line brings the
 * subtotal up to the user charge, so lines plus markup always add up exactly.
 */
final readonly class Estimate
{
    /**
     * @param  list<array{type: string, min_tokens: int, max_tokens: int, min_nanos: int, max_nanos: int}>  $lines
     */
    public function __construct(
        public int $minNanos,
        public int $maxNanos,
        public int $providerMinNanos,
        public int $providerMaxNanos,
        public array $lines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'min_nanos' => $this->minNanos,
            'max_nanos' => $this->maxNanos,
            'provider_min_nanos' => $this->providerMinNanos,
            'provider_max_nanos' => $this->providerMaxNanos,
            'markup_min_nanos' => $this->minNanos - $this->providerMinNanos,
            'markup_max_nanos' => $this->maxNanos - $this->providerMaxNanos,
            'lines' => $this->lines,
        ];
    }
}
