<?php

namespace App\Generations;

use App\Billing\CostCalculator;
use App\Models\Generation;
use App\Pixelforge\PriceSheet;
use App\Pixelforge\PricingRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a generation's status changes.
 *
 * The create response, webhooks and polling all pass Pixelforge's generation
 * object here. The row is locked, status only moves forward, and the cost is
 * recorded on the single transition into a final state, so it happens once.
 */
class GenerationStatusUpdater
{
    public function __construct(private PricingRepository $pricing) {}

    /**
     * @param  array<string, mixed>  $object  Pixelforge's generation object.
     * @param  string  $source  Where the object came from: create, webhook or poll.
     */
    public function apply(Generation $generation, array $object, string $source): Generation
    {
        return DB::transaction(function () use ($generation, $object, $source): Generation {
            $locked = $this->lock($generation);
            $status = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

            if ($status === null) {
                Log::warning('Ignored a Pixelforge generation object with an unknown status.', [
                    'generation_id' => $locked->id,
                    'source' => $source,
                ]);

                return $locked;
            }

            $this->copyPixelforgeIdentity($locked, $object);

            if ($status->rank() > $locked->status->rank()) {
                $this->moveTo($locked, $status, $object);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Fail a generation that Pixelforge rejected before creating anything.
     */
    public function failAtSubmission(Generation $generation, string $code, ?string $message = null): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message): Generation {
            $locked = $this->lock($generation);

            if ($locked->status !== GenerationStatus::Submitting) {
                return $locked;
            }

            $locked->forceFill([
                'status' => GenerationStatus::Failed,
                'status_changed_at' => now(),
                'pending_reason' => null,
                'next_attempt_at' => null,
                'failure_code' => $code,
                'failure_message' => $message,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
                'completed_at' => now(),
            ])->save();

            return $locked;
        });
    }

    private function lock(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * A webhook can arrive before the create response, so whichever comes
     * first records Pixelforge's id and the rates this generation is billed at.
     *
     * @param  array<string, mixed>  $object
     */
    private function copyPixelforgeIdentity(Generation $generation, array $object): void
    {
        $generation->pixelforge_id ??= $object['id'] ?? null;

        if ($generation->rates !== null || ! isset($object['rates'])) {
            return;
        }

        $generation->rates = $object['rates'];

        if (PriceSheet::parseRates($object['rates']) !== PriceSheet::parseRates($generation->estimate_rates)) {
            $this->reestimateAtFrozenRates($generation);
        }
    }

    /**
     * Pixelforge changed its prices between our estimate and its acceptance,
     * so recompute the estimate at the rates it will actually bill.
     */
    private function reestimateAtFrozenRates(Generation $generation): void
    {
        $sheet = $this->pricing->current();

        if ($sheet === null) {
            return;
        }

        $estimate = CostCalculator::estimate(
            $sheet->withRates($generation->rates),
            $generation->prompt,
            $generation->size,
            $generation->quality,
            count($generation->reference_images),
        );

        $generation->estimate_min_nanos = $estimate->minNanos;
        $generation->estimate_max_nanos = $estimate->maxNanos;
        $generation->estimate_rates = $generation->rates;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function moveTo(Generation $generation, GenerationStatus $status, array $object): void
    {
        $generation->forceFill([
            'status' => $status,
            'status_changed_at' => now(),
            'pending_reason' => null,
            'next_attempt_at' => null,
            'started_at' => $this->timestamp($object['started_at'] ?? null) ?? $generation->started_at,
            'completed_at' => $this->timestamp($object['completed_at'] ?? null),
        ]);

        if ($status === GenerationStatus::Succeeded) {
            $providerCost = CostCalculator::providerCost(PriceSheet::parseRates($generation->rates), $object['usage']);

            $generation->forceFill([
                'usage' => $object['usage'],
                'image_url' => $object['image_url'] ?? null,
                'image_url_expires_at' => $this->timestamp($object['image_url_expires_at'] ?? null),
                'provider_cost_nanos' => $providerCost,
                'charge_nanos' => CostCalculator::withMarkup($providerCost),
            ]);
        }

        if ($status === GenerationStatus::Failed) {
            $generation->forceFill([
                'failure_code' => $object['error']['code'] ?? null,
                'failure_message' => $object['error']['message'] ?? null,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
            ]);
        }
    }

    private function timestamp(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
