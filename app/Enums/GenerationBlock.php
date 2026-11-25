<?php

namespace App\Enums;

/**
 * Why a user can't start a generation right now.
 */
enum GenerationBlock: string
{
    case Disabled = 'disabled';

    case Suspended = 'suspended';

    case TooManyUnfinished = 'too_many_unfinished';

    case UserDailyGenerations = 'user_daily_generations';

    case UserDailySpend = 'user_daily_spend';

    case AppDailyGenerations = 'app_daily_generations';

    case AppDailySpend = 'app_daily_spend';

    public function message(): string
    {
        return match ($this) {
            self::Disabled => 'Generating is turned off for now. Please try again later.',
            self::Suspended => __('auth.suspended'),
            self::TooManyUnfinished => 'Wait for one of your running generations to finish before starting another.',
            self::UserDailyGenerations => "You've reached today's limit on generations. It resets at midnight UTC.",
            self::UserDailySpend => "This would take you past today's spending limit. It resets at midnight UTC.",
            self::AppDailyGenerations, self::AppDailySpend => "PromptEdit has reached today's limit for everyone. Please try again after midnight UTC.",
        };
    }
}
