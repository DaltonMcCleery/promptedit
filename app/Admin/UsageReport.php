<?php

namespace App\Admin;

use App\Billing\Nanos;
use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * What users were charged, what Pixelforge charged us, and the profit, per
 * UTC day and per user, over the last 30 days.
 *
 * Generations count on the UTC day they completed, as GET /v1/usage bills
 * them, so each day's provider cost can be compared with Pixelforge's own.
 * All money is integer nanodollars.
 */
class UsageReport
{
    public const DAYS = 30;

    private const USAGE_CACHE_SECONDS = 120;

    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     comparison_available: bool,
     *     days: list<array<string, mixed>>,
     *     users: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     * }
     */
    public function forLastThirtyDays(): array
    {
        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays(self::DAYS - 1);

        $billed = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $end->addDay());

        $ours = $billed->clone()
            ->toBase()
            ->selectRaw("to_char(completed_at, 'YYYY-MM-DD') as day, count(*) as generations, sum(charge_nanos) as charge, sum(provider_cost_nanos) as provider")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $pixelforge = $this->pixelforgeCostsByDay($start->toDateString(), $end->toDateString());

        $days = [];

        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $date = $day->toDateString();
            $row = $ours->get($date);

            $days[] = $this->compared(
                $this->row(
                    ['date' => $date],
                    (int) ($row->generations ?? 0),
                    (int) ($row->charge ?? 0),
                    (int) ($row->provider ?? 0),
                ),
                $pixelforge === null ? null : ($pixelforge[$date] ?? 0),
            );
        }

        $users = $billed->clone()
            ->toBase()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw('users.id, users.name, users.email, count(*) as generations, sum(charge_nanos) as charge, sum(provider_cost_nanos) as provider')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByRaw('sum(charge_nanos) desc')
            ->get()
            ->map(fn ($user) => $this->row(
                ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                (int) $user->generations,
                (int) $user->charge,
                (int) $user->provider,
            ))
            ->values()
            ->all();

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'comparison_available' => $pixelforge !== null,
            'days' => $days,
            'users' => $users,
            'totals' => $this->compared(
                $this->row(
                    [],
                    array_sum(array_column($days, 'generations')),
                    array_sum(array_column($days, 'charge_nanos')),
                    array_sum(array_column($days, 'provider_cost_nanos')),
                ),
                $pixelforge === null ? null : array_sum($pixelforge),
            ),
        ];
    }

    /**
     * Pixelforge's billed cost per day in nanodollars, or null if it can't be
     * reached. Only successful responses are cached, for 2 minutes.
     *
     * @return array<string, int>|null
     */
    private function pixelforgeCostsByDay(string $startDate, string $endDate): ?array
    {
        $key = "pixelforge.usage.{$startDate}.{$endDate}";
        $body = Cache::get($key);

        if ($body === null) {
            try {
                $body = $this->client->usage($startDate, $endDate);
            } catch (ConnectionException|RequestException) {
                return null;
            }

            Cache::put($key, $body, self::USAGE_CACHE_SECONDS);
        }

        $costs = [];

        foreach ($body['data'] ?? [] as $day) {
            $costs[$day['date']] = Nanos::fromDecimal((string) $day['cost_usd']);
        }

        return $costs;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function row(array $identity, int $generations, int $charge, int $provider): array
    {
        return [
            ...$identity,
            'generations' => $generations,
            'charge_nanos' => $charge,
            'provider_cost_nanos' => $provider,
            'profit_nanos' => $charge - $provider,
        ];
    }

    /**
     * Add Pixelforge's figure and our difference from it (ours − theirs).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function compared(array $row, ?int $pixelforgeCost): array
    {
        return [
            ...$row,
            'pixelforge_cost_nanos' => $pixelforgeCost,
            'difference_nanos' => $pixelforgeCost === null ? null : $row['provider_cost_nanos'] - $pixelforgeCost,
        ];
    }
}
