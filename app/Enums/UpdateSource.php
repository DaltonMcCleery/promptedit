<?php

namespace App\Enums;

/**
 * Where a Pixelforge generation object came from. All three go through the
 * same status rules; the source is only recorded in logs.
 */
enum UpdateSource: string
{
    case CreateResponse = 'create';
    case Webhook = 'webhook';
    case Poll = 'poll';
}
