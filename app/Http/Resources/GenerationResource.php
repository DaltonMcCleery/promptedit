<?php

namespace App\Http\Resources;

use App\Enums\FailureCode;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as History shows it. Money stays in integer nanodollars; the
 * page formats it.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_count' => count($this->reference_images),
            'status' => $this->status->value,
            'pending_reason' => $this->pending_reason?->value,
            'is_final' => $this->status->isFinal(),
            'estimate_min_nanos' => $this->estimate_min_nanos,
            'estimate_max_nanos' => $this->estimate_max_nanos,
            'charge_nanos' => $this->charge_nanos,
            'failure_reason' => $this->status === GenerationStatus::Failed ? FailureCode::reasonFor($this->failure_code) : null,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
