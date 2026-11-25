<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\PendingReason;
use App\Enums\Quality;
use App\Enums\Size;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One image request from a user, created before anything is sent to Pixelforge.
 *
 * Money columns are integer nanodollars (1 USD = 1,000,000,000).
 *
 * @property string $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string|null $pixelforge_id
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property PendingReason|null $pending_reason
 * @property int $submit_attempts
 * @property int $unreachable_attempts
 * @property Carbon|null $waiting_since
 * @property Carbon|null $next_attempt_at
 * @property int $estimate_min_nanos
 * @property int $estimate_max_nanos
 * @property array<string, string> $estimate_rates
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_polled_at
 * @property Carbon $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'idempotency_key',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'status',
    'next_attempt_at',
    'estimate_min_nanos',
    'estimate_max_nanos',
    'estimate_rates',
    'status_changed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Not yet sent to, or not yet accepted by, Pixelforge.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function submitting(Builder $query): void
    {
        $query->where('status', GenerationStatus::Submitting);
    }

    /**
     * Using one of Pixelforge's concurrent slots: queued or processing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inFlight(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * Not finished yet: submitting, queued or processing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unfinished(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Submitting, GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * Created since midnight UTC (the app's timezone), for the daily limits.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function createdToday(Builder $query): void
    {
        $query->where('created_at', '>=', today());
    }

    /**
     * Finished with an image, so billed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function succeeded(Builder $query): void
    {
        $query->where('status', GenerationStatus::Succeeded);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'status' => GenerationStatus::class,
            'pending_reason' => PendingReason::class,
            'submit_attempts' => 'integer',
            'unreachable_attempts' => 'integer',
            'waiting_since' => 'datetime',
            'next_attempt_at' => 'datetime',
            'estimate_min_nanos' => 'integer',
            'estimate_max_nanos' => 'integer',
            'estimate_rates' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }
}
