<?php

namespace App\Enums;

/**
 * Why a generation failed: Pixelforge's error codes, plus the app's own
 * ConcurrencyTimeout. The `failure_code` column stays a string, because
 * Pixelforge may add codes the app doesn't know yet.
 */
enum FailureCode: string
{
    // Pixelforge accepted the generation, then it failed.
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    // Pixelforge rejected the request; nothing was created or billed.
    case ContentPolicyViolation = 'content_policy_violation';
    case Overloaded = 'overloaded';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case ValidationFailed = 'validation_failed';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case InvalidApiKey = 'invalid_api_key';

    // The app gave up waiting for one of Pixelforge's concurrent slots.
    case ConcurrencyTimeout = 'concurrency_timeout';

    /**
     * The plain-language reason History shows for any stored code, known or not.
     */
    public static function reasonFor(?string $code): string
    {
        return self::tryFrom((string) $code)?->reason()
            ?? sprintf('Pixelforge rejected the request (%s).', $code ?? 'unknown error');
    }

    public function reason(): string
    {
        return match ($this) {
            self::GenerationFailed => "Pixelforge couldn't produce an image for this prompt.",
            self::InternalError => 'Something went wrong at Pixelforge. Trying again usually works.',
            self::ContentPolicyViolation => 'Pixelforge blocked this prompt.',
            self::DailyLimitExceeded => "Pixelforge's daily generation limit was reached. Try again after midnight UTC.",
            self::Overloaded => 'Pixelforge stayed overloaded after 5 tries.',
            self::RateLimitExceeded => 'Too many requests to Pixelforge, even after 5 tries.',
            self::ConcurrencyTimeout => 'No free generation slot opened within 10 minutes.',
            // These mean a bug or bad configuration on our side, so name the code.
            self::ConcurrencyLimitExceeded,
            self::ValidationFailed,
            self::IdempotencyKeyReused,
            self::InvalidApiKey => "Pixelforge rejected the request ({$this->value}).",
        };
    }
}
