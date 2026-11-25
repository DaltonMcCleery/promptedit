<?php

namespace App\Services;

use App\Enums\GenerationBlock;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\Nanos;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The caps in config/limits.php: per user, across the app, and the switch
 * that turns generating off.
 *
 * Spend counts what is already billed plus the highest estimate of anything
 * still unfinished, so a burst of submissions can't slip past a cap before
 * Pixelforge reports their real cost.
 */
class GenerationLimits
{
    /** Key for the Postgres advisory lock that serializes the check and the insert. */
    private const int LOCK_KEY = 7_340_001;

    /**
     * Refuse the generation when it would break a limit.
     *
     * Call inside the transaction that inserts the generation: the lock is
     * held until it commits, so two requests can't both pass a check that
     * only one of them should.
     *
     * @throws ValidationException naming the limit that was hit.
     */
    public function ensureAllowed(User $user, int $estimateMaxNanos): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenerationLimits::ensureAllowed() must run inside a transaction.');
        }

        DB::statement('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

        $block = $this->blockFor($user, $estimateMaxNanos);

        if ($block !== null) {
            throw ValidationException::withMessages(['limit' => $block->message()]);
        }
    }

    /**
     * The first limit a generation costing up to $estimateMaxNanos would break, if any.
     */
    public function blockFor(User $user, int $estimateMaxNanos = 0): ?GenerationBlock
    {
        if (! config()->boolean('limits.generations_enabled')) {
            return GenerationBlock::Disabled;
        }

        if ($user->isSuspended()) {
            return GenerationBlock::Suspended;
        }

        if (! $user->is_admin) {
            $usage = $this->userUsage($user);

            if ($usage['unfinished'] >= config()->integer('limits.user.unfinished')) {
                return GenerationBlock::TooManyUnfinished;
            }

            if ($usage['generations'] >= config()->integer('limits.user.daily_generations')) {
                return GenerationBlock::UserDailyGenerations;
            }

            if ($this->userSpendLimit() < $usage['spend_nanos'] + $estimateMaxNanos) {
                return GenerationBlock::UserDailySpend;
            }
        }

        if (Generation::query()->createdToday()->count() >= config()->integer('limits.app.daily_generations')) {
            return GenerationBlock::AppDailyGenerations;
        }

        $appSpend = (int) Generation::query()->createdToday()->succeeded()->sum('provider_cost_nanos')
            + (int) Generation::query()->createdToday()->unfinished()->sum('estimate_max_nanos');

        if ($appSpend + $estimateMaxNanos > Nanos::fromDecimal(config()->string('limits.app.daily_spend'))) {
            return GenerationBlock::AppDailySpend;
        }

        return null;
    }

    /**
     * What a user has used today, for the generate page and the checks above.
     *
     * @return array{unfinished: int, generations: int, spend_nanos: int}
     */
    public function userUsage(User $user): array
    {
        return [
            'unfinished' => $user->generations()->unfinished()->count(),
            'generations' => $user->generations()->createdToday()->count(),
            'spend_nanos' => (int) $user->generations()->createdToday()->succeeded()->sum('charge_nanos')
                + (int) $user->generations()->createdToday()->unfinished()->sum('estimate_max_nanos'),
        ];
    }

    /**
     * The generate page's view of the limits. Per-user numbers are null for admins, who skip them.
     *
     * @return array{blocked: string|null, user: array<string, int>|null}
     */
    public function forPage(User $user): array
    {
        $usage = $user->is_admin ? null : $this->userUsage($user);

        return [
            'blocked' => $this->blockFor($user)?->message(),
            'user' => $usage === null ? null : [
                'unfinished' => $usage['unfinished'],
                'unfinished_limit' => config()->integer('limits.user.unfinished'),
                'generations' => $usage['generations'],
                'generations_limit' => config()->integer('limits.user.daily_generations'),
                'spend_nanos' => $usage['spend_nanos'],
                'spend_limit_nanos' => $this->userSpendLimit(),
            ],
        ];
    }

    private function userSpendLimit(): int
    {
        return Nanos::fromDecimal(config()->string('limits.user.daily_spend'));
    }
}
