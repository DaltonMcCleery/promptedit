<?php

namespace App\Pixelforge;

/**
 * What a POST /v1/generations response means for the generation.
 */
enum CreateOutcome: string
{
    /** Pixelforge accepted it (202), including an idempotent replay. */
    case Accepted = 'accepted';

    /** 503 overloaded or 429 rate limit: nothing was created; retry after Retry-After. */
    case Retryable = 'retryable';

    /** 429 concurrency limit: nothing was created; wait for a free slot. */
    case NoSlot = 'no_slot';

    /** A rejection that retrying cannot fix: nothing was created or billed. */
    case Final = 'final';

    /** Timeout, connection error or an unexpected 5xx: Pixelforge may have created it. */
    case Unknown = 'unknown';
}
