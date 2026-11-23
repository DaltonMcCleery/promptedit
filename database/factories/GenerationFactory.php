<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostCalculator;
use App\Services\Pixelforge\PriceSheet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults are a medium 1024x1024 generation with no reference images,
 * at Pixelforge's published rates.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => fake()->sentence(),
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'status' => GenerationStatus::Submitting,
            'next_attempt_at' => now(),
            // A 40-character prompt at medium 1024x1024: 8-12 text, 1,100 output and 200-2,000 reasoning tokens.
            'estimate_min_nanos' => 58_175_000,
            'estimate_max_nanos' => 86_325_000,
            'estimate_rates' => self::RATES,
            'status_changed_at' => now(),
        ];
    }

    public function queued(): static
    {
        return $this->acceptedAs(GenerationStatus::Queued);
    }

    public function processing(): static
    {
        return $this->acceptedAs(GenerationStatus::Processing)->state(fn () => [
            'started_at' => now(),
        ]);
    }

    /**
     * Succeeded with 10 text, 1,100 output and 800 reasoning tokens.
     */
    public function succeeded(): static
    {
        $usage = [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1_100,
            'reasoning_tokens' => 800,
            'total_tokens' => 1_910,
        ];
        $providerCost = CostCalculator::providerCost(PriceSheet::parseRates(self::RATES), $usage);

        return $this->acceptedAs(GenerationStatus::Succeeded)->state(fn () => [
            'usage' => $usage,
            'provider_cost_nanos' => $providerCost,
            'charge_nanos' => CostCalculator::withMarkup($providerCost),
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.fake()->uuid().'.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->acceptedAs(GenerationStatus::Failed)->state(fn () => [
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'failure_code' => $code,
            'failure_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }

    private function acceptedAs(GenerationStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'pixelforge_id' => 'gen_'.Str::upper((string) Str::ulid()),
            'rates' => self::RATES,
            'next_attempt_at' => null,
        ]);
    }
}
