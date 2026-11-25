<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostCalculator;
use App\Services\GenerationLimits;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Save a generation before Pixelforge hears about it, then queue the request.
 *
 * The row and its idempotency key exist first, so a webhook that beats the
 * create response finds it and every retry reuses the same key.
 */
class CreateGeneration
{
    public function __construct(
        private PricingRepository $pricing,
        private GenerationLimits $limits,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>|null}  $input
     *
     * @throws ValidationException when Pixelforge's prices are unavailable or a limit would be broken.
     */
    public function handle(User $user, array $input): Generation
    {
        $sheet = $this->pricing->current() ?? throw ValidationException::withMessages([
            'pricing' => "Prices are unavailable right now, so we can't show what this would cost. Please try again shortly.",
        ]);

        $size = Size::from($input['size']);
        $quality = Quality::from($input['quality']);
        $referenceImages = $input['reference_images'] ?? [];
        $estimate = CostCalculator::estimate($sheet, $input['prompt'], $size, $quality, count($referenceImages));

        return DB::transaction(function () use ($user, $input, $size, $quality, $referenceImages, $estimate, $sheet): Generation {
            // Held until commit, so the limit check and the insert happen as one step.
            $this->limits->lock();
            $this->limits->ensureAllowed($user, $estimate->maxNanos);

            $generation = $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'status' => GenerationStatus::Submitting,
                'next_attempt_at' => now(),
                'estimate_min_nanos' => $estimate->minNanos,
                'estimate_max_nanos' => $estimate->maxNanos,
                'estimate_rates' => $sheet->rateStrings,
                'status_changed_at' => now(),
            ]);

            SubmitGeneration::dispatch($generation)->afterCommit();

            return $generation;
        });
    }
}
