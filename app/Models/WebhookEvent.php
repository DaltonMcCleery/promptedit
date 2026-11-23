<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Pixelforge webhook event that has been received and applied.
 *
 * The unique event_id is what stops a repeated delivery being applied twice.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
