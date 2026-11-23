<?php

namespace Database\Seeders;

use App\Billing\CostCalculator;
use App\Generations\GenerationStatus;
use App\Generations\Quality;
use App\Generations\Size;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PriceSheet;
use App\Pixelforge\PricingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Local-only demo history: about a month of generations from three demo
 * users, for trying History and the admin report.
 *
 * Pixelforge never ran these, so on the admin report their days won't match
 * GET /v1/usage. That's why this refuses to run in production, and why it
 * leaves today alone: real generations made today still reconcile.
 *
 * Every amount goes through the same CostCalculator as real generations, at
 * Pixelforge's current rates. The same seed gives the same data every run.
 *
 *     php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.promptedit.test';

    public const PASSWORD = 'demo-password';

    /** Days back from yesterday that get history. */
    private const DAYS = 29;

    private const USERS = [
        ['name' => 'Ava Chen', 'email' => 'ava', 'per_day' => [0, 4]],
        ['name' => 'Marcus Webb', 'email' => 'marcus', 'per_day' => [0, 3]],
        ['name' => 'Priya Patel', 'email' => 'priya', 'per_day' => [0, 2]],
    ];

    private const PROMPTS = [
        'A lighthouse on a cliff at dawn, oil painting',
        'A red bicycle leaning on a brick wall in the rain',
        'Isometric city block at night, neon signs, light fog',
        'A bowl of lemons on a linen tablecloth, soft window light',
        'Watercolour map of an imaginary archipelago',
        'A fox curled up in fresh snow, close-up, shallow depth of field',
        'Retro travel poster for a city on Mars',
        'Two cats asleep on a green velvet sofa',
        'A paper boat on a puddle reflecting skyscrapers',
        'Botanical illustration of a fern, ink and wash',
        'A cosy reading nook with a round window and rain outside',
        'Minimal logo of a mountain made of three triangles',
    ];

    /** Pixelforge's two failure codes after acceptance, and two rejections at submission. */
    private const FAILURES_AFTER_ACCEPTANCE = ['generation_failed', 'internal_error'];

    private const FAILURES_AT_SUBMISSION = ['overloaded', 'content_policy_violation'];

    private Randomizer $random;

    public function __construct(private PricingRepository $pricing) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder adds generations Pixelforge never billed, so it never runs in production.');
        }

        $sheet = $this->pricing->current()
            ?? throw new RuntimeException("Couldn't load Pixelforge's pricing, which the demo amounts are calculated from.");

        $this->random = new Randomizer(new Mt19937(20261123));
        $yesterday = CarbonImmutable::now('UTC')->startOfDay()->subDay();
        $count = 0;

        foreach (self::USERS as $demo) {
            $user = $this->demoUser($demo['name'], $demo['email']);
            $user->generations()->delete();

            for ($daysAgo = 0; $daysAgo < self::DAYS; $daysAgo++) {
                $day = $yesterday->subDays($daysAgo);
                $perDay = $this->random->getInt(...$demo['per_day']);

                for ($i = 0; $i < $perDay; $i++) {
                    $this->generation($user, $sheet, $day->addSeconds($this->random->getInt(6 * 3600, 22 * 3600)));
                    $count++;
                }
            }
        }

        $this->command?->info("Seeded {$count} demo generations for ".count(self::USERS).' users (password: '.self::PASSWORD.').');
    }

    private function demoUser(string $name, string $localPart): User
    {
        $user = User::query()->firstOrNew(['email' => $localPart.'@'.self::EMAIL_DOMAIN]);
        $user->forceFill([
            'name' => $name,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function generation(User $user, PriceSheet $sheet, CarbonImmutable $createdAt): void
    {
        $prompt = self::PROMPTS[$this->random->getInt(0, count(self::PROMPTS) - 1)];
        $size = $this->pick(Size::cases());
        $quality = $this->pick([Quality::Low, Quality::Medium, Quality::Medium, Quality::High]);
        $references = $this->pick([0, 0, 0, 1, 2]);
        $estimate = CostCalculator::estimate($sheet, $prompt, $size, $quality, $references);

        $generation = new Generation;
        $generation->forceFill([
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $references === 0
                ? []
                : array_map(fn (int $n) => "https://example.com/style-{$n}.png", range(1, $references)),
            'estimate_min_nanos' => $estimate->minNanos,
            'estimate_max_nanos' => $estimate->maxNanos,
            'estimate_rates' => $sheet->rateStrings,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $outcome = $this->random->getInt(1, 100);

        if ($outcome <= 4) {
            $this->failAtSubmission($generation, $createdAt);
        } elseif ($outcome <= 12) {
            $this->failAfterAcceptance($generation, $sheet, $createdAt);
        } else {
            $this->succeed($generation, $sheet, $createdAt, $estimate->lines);
        }

        $generation->save();
    }

    /**
     * Usage inside the ranges Pixelforge documents: text within ±15% of
     * ceil(characters / 4), reasoning within the quality's min-max.
     *
     * @param  list<array{type: string, min_tokens: int, max_tokens: int}>  $lines
     */
    private function succeed(Generation $generation, PriceSheet $sheet, CarbonImmutable $createdAt, array $lines): void
    {
        $usage = ['total_tokens' => 0];

        foreach ($lines as $line) {
            $usage[$line['type']] = $this->random->getInt($line['min_tokens'], $line['max_tokens']);
            $usage['total_tokens'] += $usage[$line['type']];
        }

        $providerCost = CostCalculator::providerCost($sheet->rates, $usage);
        $completedAt = $createdAt->addSeconds($this->random->getInt(8, 40));

        $this->accepted($generation, $sheet, $createdAt, $completedAt)->forceFill([
            'status' => GenerationStatus::Succeeded,
            'usage' => $usage,
            'provider_cost_nanos' => $providerCost,
            'charge_nanos' => CostCalculator::withMarkup($providerCost),
            // Pixelforge's image links expire after 24 hours, so History shows the expired placeholder.
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.$generation->pixelforge_id.'.png',
            'image_url_expires_at' => $completedAt->addDay(),
        ]);
    }

    private function failAfterAcceptance(Generation $generation, PriceSheet $sheet, CarbonImmutable $createdAt): void
    {
        $code = $this->pick(self::FAILURES_AFTER_ACCEPTANCE);

        $this->accepted($generation, $sheet, $createdAt, $createdAt->addSeconds($this->random->getInt(8, 30)))->forceFill([
            'status' => GenerationStatus::Failed,
            'failure_code' => $code,
            'failure_message' => $code === 'internal_error'
                ? 'Something went wrong on our side while generating. You were not charged; please retry.'
                : 'The model could not produce an image for this prompt.',
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
        ]);
    }

    private function failAtSubmission(Generation $generation, CarbonImmutable $createdAt): void
    {
        $code = $this->pick(self::FAILURES_AT_SUBMISSION);

        $generation->forceFill([
            'status' => GenerationStatus::Failed,
            'status_changed_at' => $createdAt,
            'submit_attempts' => $code === 'overloaded' ? 4 : 0,
            'failure_code' => $code,
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'completed_at' => $createdAt,
        ]);
    }

    private function accepted(Generation $generation, PriceSheet $sheet, CarbonImmutable $createdAt, CarbonImmutable $completedAt): Generation
    {
        return $generation->forceFill([
            'pixelforge_id' => 'gen_demo_'.Str::upper((string) Str::ulid()),
            'rates' => $sheet->rateStrings,
            'started_at' => $createdAt->addSeconds(2),
            'completed_at' => $completedAt,
            'status_changed_at' => $completedAt,
        ]);
    }

    /**
     * @template T
     *
     * @param  list<T>  $options
     * @return T
     */
    private function pick(array $options): mixed
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }
}
