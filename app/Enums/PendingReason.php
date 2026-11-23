<?php

namespace App\Enums;

/**
 * Why a generation is still submitting, shown as its badge.
 */
enum PendingReason: string
{
    /** Pixelforge's 3 concurrent generations are in use. */
    case WaitingForSlot = 'waiting_for_slot';

    /** The create request timed out or failed; retrying with the same idempotency key. */
    case Unreachable = 'unreachable';
}
