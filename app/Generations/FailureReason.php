<?php

namespace App\Generations;

/**
 * The plain-language reason History shows for a failed generation.
 */
final class FailureReason
{
    /** The app's own code when no Pixelforge slot opened within 10 minutes. */
    public const CONCURRENCY_TIMEOUT = 'concurrency_timeout';

    private const REASONS = [
        'generation_failed' => "Pixelforge couldn't produce an image for this prompt.",
        'internal_error' => 'Something went wrong at Pixelforge. Trying again usually works.',
        'content_policy_violation' => 'Pixelforge blocked this prompt.',
        'daily_limit_exceeded' => "Pixelforge's daily generation limit was reached. Try again after midnight UTC.",
        'overloaded' => 'Pixelforge stayed overloaded after 5 tries.',
        'rate_limit_exceeded' => 'Too many requests to Pixelforge, even after 5 tries.',
        self::CONCURRENCY_TIMEOUT => 'No free generation slot opened within 10 minutes.',
    ];

    public static function for(?string $code): string
    {
        return self::REASONS[$code] ?? sprintf('Pixelforge rejected the request (%s).', $code ?? 'unknown error');
    }
}
