<?php

namespace App\Enums;

/**
 * What the webhook endpoint did with a verified event. Every outcome answers
 * 2xx, so Pixelforge stops retrying.
 */
enum WebhookOutcome: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Ping = 'ping';
    case UnknownGeneration = 'unknown_generation';
}
