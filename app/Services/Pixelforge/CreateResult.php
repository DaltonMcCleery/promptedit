<?php

namespace App\Services\Pixelforge;

use App\Enums\CreateOutcome;

final readonly class CreateResult
{
    /**
     * @param  array<string, mixed>|null  $generation  Pixelforge's generation object when accepted.
     */
    public function __construct(
        public CreateOutcome $outcome,
        public ?array $generation = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $retryAfterSeconds = null,
        public ?int $httpStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $generation
     */
    public static function accepted(array $generation): self
    {
        return new self(CreateOutcome::Accepted, generation: $generation);
    }
}
