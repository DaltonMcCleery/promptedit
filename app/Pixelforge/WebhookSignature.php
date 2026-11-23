<?php

namespace App\Pixelforge;

/**
 * Checks the Pixelforge-Signature header: t=<unix timestamp>,v1=<hex HMAC-SHA256
 * of "<timestamp>.<raw body>"> keyed with the API key's signing secret.
 */
final class WebhookSignature
{
    /** Reject timestamps further than this from our clock, to stop replays. */
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $payload, ?string $header, string $secret, int $now): bool
    {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a header the way Pixelforge does; used by tests and local tooling.
     */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
