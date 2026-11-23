<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const USAGE_URL = 'https://pixelforge.promptedit.dev/v1/usage*';

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pf_test_key']);
    Http::preventStrayRequests();
    $this->travelTo('2026-11-23 15:00:00');
});

/**
 * A GET /v1/usage body covering the report's 30 days, with the given costs per date.
 *
 * @param  array<string, string>  $costs
 * @return array<string, mixed>
 */
function usageResponse(array $costs = []): array
{
    $data = [];

    for ($day = now()->utc()->startOfDay()->subDays(29); $day->lte(now()->utc()->startOfDay()); $day = $day->addDay()) {
        $data[] = ['date' => $day->toDateString(), 'generations' => 0, 'cost_usd' => $costs[$day->toDateString()] ?? '0.000000000'];
    }

    return ['object' => 'usage', 'data' => $data];
}

/**
 * A succeeded generation that cost Pixelforge $provider and the user $charge (in nanodollars).
 */
function billedGeneration(User $user, string $completedAt, int $provider): Generation
{
    return Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => $completedAt,
        'provider_cost_nanos' => $provider,
        'charge_nanos' => intdiv($provider * 5 + 3, 4),
    ]);
}

it('is only for admins', function () {
    Http::fake([USAGE_URL => Http::response(usageResponse())]);

    $this->get(route('admin.report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))->assertOk();
});

it('grants the admin flag by email', function () {
    $user = User::factory()->create(['email' => 'operator@example.com']);

    $this->artisan('app:make-admin', ['email' => 'operator@example.com'])->assertSuccessful();
    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('totals charges, provider costs and profit per UTC day of completion', function () {
    Http::fake([USAGE_URL => Http::response(usageResponse())]);
    $user = User::factory()->create();
    billedGeneration($user, '2026-11-22 23:59:58', 305_510_000);
    billedGeneration($user, '2026-11-23 00:00:03', 75_290_000);
    billedGeneration($user, '2026-11-23 09:00:00', 11_752_500);
    Generation::factory()->for($user)->failed()->create(['completed_at' => '2026-11-23 10:00:00']);
    billedGeneration($user, '2026-10-01 12:00:00', 1_000_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->where('start_date', '2026-10-25')
            ->where('end_date', '2026-11-23')
            ->has('days', 30)
            ->where('days.0.date', '2026-11-23')
            ->where('days.0.generations', 2)
            ->where('days.0.provider_cost_nanos', 87_042_500)
            ->where('days.0.charge_nanos', 108_803_125)
            ->where('days.0.profit_nanos', 21_760_625)
            ->where('days.1.date', '2026-11-22')
            ->where('days.1.generations', 1)
            ->where('days.1.provider_cost_nanos', 305_510_000)
            ->where('days.2.generations', 0)
            ->where('days.2.charge_nanos', 0)
            ->where('totals.generations', 3)
            ->where('totals.provider_cost_nanos', 392_552_500)
            ->where('totals.profit_nanos', 98_138_125));
});

it('totals each user over the range', function () {
    Http::fake([USAGE_URL => Http::response(usageResponse())]);
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    billedGeneration($alice, '2026-11-20 10:00:00', 100_000_000);
    billedGeneration($alice, '2026-11-21 10:00:00', 20_000_000);
    billedGeneration($bob, '2026-11-21 11:00:00', 40_000_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users', 2)
            ->where('users.0.email', 'alice@example.com')
            ->where('users.0.generations', 2)
            ->where('users.0.provider_cost_nanos', 120_000_000)
            ->where('users.0.charge_nanos', 150_000_000)
            ->where('users.0.profit_nanos', 30_000_000)
            ->where('users.1.email', 'bob@example.com')
            ->where('users.1.charge_nanos', 50_000_000));
});

it('compares each day with what Pixelforge billed', function () {
    Http::fake([USAGE_URL => Http::response(usageResponse([
        '2026-11-23' => '0.305510000',
        '2026-11-22' => '0.075865000',
    ]))]);
    $user = User::factory()->create();
    billedGeneration($user, '2026-11-23 09:00:00', 305_510_000);
    billedGeneration($user, '2026-11-22 09:00:00', 75_290_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison_available', true)
            ->where('days.0.pixelforge_cost_nanos', 305_510_000)
            ->where('days.0.difference_nanos', 0)
            ->where('days.1.pixelforge_cost_nanos', 75_865_000)
            ->where('days.1.difference_nanos', -575_000)
            ->where('days.2.difference_nanos', 0)
            ->where('totals.difference_nanos', -575_000));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-10-25&end_date=2026-11-23');
});

it('caches the usage report for 2 minutes', function () {
    Http::fake([USAGE_URL => Http::response(usageResponse())]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.report'));
    $this->travel(119)->seconds();
    $this->actingAs($admin)->get(route('admin.report'));
    $this->travel(2)->seconds();
    $this->actingAs($admin)->get(route('admin.report'));

    Http::assertSentCount(2);
});

it('still shows our own numbers when Pixelforge cannot be reached', function (Closure $failure) {
    Http::fake([USAGE_URL => $failure]);
    billedGeneration(User::factory()->create(), '2026-11-23 09:00:00', 305_510_000);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison_available', false)
            ->where('days.0.provider_cost_nanos', 305_510_000)
            ->where('days.0.pixelforge_cost_nanos', null)
            ->where('days.0.difference_nanos', null));
})->with([
    'overloaded' => [fn () => fn () => pixelforgeError(503, 'overloaded')],
    'timeout' => [fn () => fn () => throw new ConnectionException('Operation timed out')],
]);
