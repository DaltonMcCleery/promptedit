<?php

use App\Enums\GenerationBlock;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostCalculator;
use App\Services\Billing\Nanos;
use App\Services\GenerationLimits;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([SubmitGeneration::class]);
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
});

const LIMITED_PROMPT = 'A lighthouse on a cliff at dawn';

/**
 * A low-quality square request, the cheapest kind.
 *
 * @return array<string, mixed>
 */
function cheapGeneration(): array
{
    return ['prompt' => LIMITED_PROMPT, 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
}

/** What cheapGeneration() can cost the user at most. */
function cheapGenerationMaxNanos(): int
{
    return CostCalculator::estimate(docsPriceSheet(), LIMITED_PROMPT, Size::Square, Quality::Low, 0)->maxNanos;
}

function submitCheap(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('generations.store'), cheapGeneration());
}

it('refuses a third unfinished generation', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();
    Generation::factory()->for($user)->processing()->create();

    submitCheap($user)->assertSessionHasErrors(['limit' => GenerationBlock::TooManyUnfinished->message()]);

    expect($user->generations()->count())->toBe(2);
    Queue::assertNotPushed(SubmitGeneration::class);
});

it('allows a new generation once earlier ones finish', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->queued()->create();

    submitCheap($user)->assertSessionHasNoErrors();

    expect($user->generations()->count())->toBe(4);
});

it('caps generations per user per UTC day, failures included', function () {
    config(['limits.user.daily_generations' => 2]);
    $user = User::factory()->create();
    Generation::factory()->for($user)->failed()->create(['created_at' => now()->subDay()]);
    Generation::factory()->for($user)->failed()->create();

    submitCheap($user)->assertSessionHasNoErrors();
    submitCheap($user)->assertSessionHasErrors(['limit' => GenerationBlock::UserDailyGenerations->message()]);

    expect($user->generations()->count())->toBe(3);
});

it('caps what a user can spend in a day, counting the most unfinished work could cost', function () {
    $user = User::factory()->create();
    $billed = Generation::factory()->for($user)->succeeded()->create();
    $running = Generation::factory()->for($user)->queued()->create();
    $used = $billed->charge_nanos + $running->estimate_max_nanos;

    // Exactly at the limit is allowed.
    config(['limits.user.daily_spend' => Nanos::toDecimal($used + cheapGenerationMaxNanos())]);
    submitCheap($user)->assertSessionHasNoErrors();

    // One nanodollar less is not. The new row is unfinished, so it counts as well.
    $user->generations()->where('prompt', LIMITED_PROMPT)->delete();
    config(['limits.user.daily_spend' => Nanos::toDecimal($used + cheapGenerationMaxNanos() - 1)]);
    submitCheap($user)->assertSessionHasErrors(['limit' => GenerationBlock::UserDailySpend->message()]);
});

it('ignores spending from earlier days', function () {
    config(['limits.user.daily_spend' => '0.10']);
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create(['created_at' => now()->subDay()]);

    submitCheap($user)->assertSessionHasNoErrors();
});

it('lets admins skip the per-user limits', function () {
    config(['limits.user.unfinished' => 0, 'limits.user.daily_generations' => 0, 'limits.user.daily_spend' => '0']);

    submitCheap(User::factory()->create(['is_admin' => true]))->assertSessionHasNoErrors();
});

it('caps generations across all users per day, admins included', function () {
    config(['limits.app.daily_generations' => 2]);
    Generation::factory()->failed()->count(2)->create();

    submitCheap(User::factory()->create(['is_admin' => true]))
        ->assertSessionHasErrors(['limit' => GenerationBlock::AppDailyGenerations->message()]);
});

it("caps Pixelforge's cost across all users per day", function () {
    $billed = Generation::factory()->succeeded()->create();
    $running = Generation::factory()->processing()->create();
    $used = $billed->provider_cost_nanos + $running->estimate_max_nanos;

    config(['limits.app.daily_spend' => Nanos::toDecimal($used + cheapGenerationMaxNanos() - 1)]);
    submitCheap(User::factory()->create(['is_admin' => true]))
        ->assertSessionHasErrors(['limit' => GenerationBlock::AppDailySpend->message()]);

    config(['limits.app.daily_spend' => Nanos::toDecimal($used + cheapGenerationMaxNanos())]);
    submitCheap(User::factory()->create())->assertSessionHasNoErrors();
});

it('stops every new generation when generating is turned off', function () {
    config(['limits.generations_enabled' => false]);

    submitCheap(User::factory()->create(['is_admin' => true]))
        ->assertSessionHasErrors(['limit' => GenerationBlock::Disabled->message()]);

    expect(Generation::count())->toBe(0);
});

it('throttles bursts of generate requests', function () {
    config(['limits.submits_per_minute' => 2]);
    $user = User::factory()->create(['is_admin' => true]);

    submitCheap($user)->assertSessionHasNoErrors();
    submitCheap($user)->assertSessionHasNoErrors();
    submitCheap($user)->assertSessionHasErrors('limit');

    expect($user->generations()->count())->toBe(2);
});

it("shows the user today's usage and limits on the generate page", function () {
    $user = User::factory()->create();
    $billed = Generation::factory()->for($user)->succeeded()->create();
    $running = Generation::factory()->for($user)->queued()->create();

    $this->actingAs($user)
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('limits.blocked', null)
            ->where('limits.user.generations', 2)
            ->where('limits.user.generations_limit', 25)
            ->where('limits.user.unfinished', 1)
            ->where('limits.user.unfinished_limit', 2)
            ->where('limits.user.spend_nanos', $billed->charge_nanos + $running->estimate_max_nanos)
            ->where('limits.user.spend_limit_nanos', 2 * Nanos::PER_DOLLAR));
});

it('tells the user why they are blocked and hides per-user usage from admins', function () {
    config(['limits.generations_enabled' => false]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('limits.blocked', GenerationBlock::Disabled->message())
            ->where('limits.user', null));
});

it('refuses a suspended user even without the sign-out middleware', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    expect(app(GenerationLimits::class)->blockFor($user))->toBe(GenerationBlock::Suspended);
});

it('must be called inside a transaction, so the lock covers the insert', function () {
    DB::rollBack(); // Leave RefreshDatabase's wrapping transaction for this check.

    try {
        expect(fn () => app(GenerationLimits::class)->ensureAllowed(User::factory()->make(), 0))
            ->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});
