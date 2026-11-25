<?php

/*
|--------------------------------------------------------------------------
| Abuse limits
|--------------------------------------------------------------------------
|
| Caps that stop one account, or many, from running up the Pixelforge bill
| or flooding the database. Days are UTC days. Dollar amounts are exact
| decimal strings, parsed to nanodollars like every other amount.
|
*/

return [

    // Set GENERATIONS_ENABLED=false to stop every new generation at once.
    'generations_enabled' => (bool) env('GENERATIONS_ENABLED', true),

    // Per user. Admins skip these, but not the app-wide budget.
    'user' => [
        // Generations still submitting, queued or processing.
        'unfinished' => (int) env('LIMIT_USER_UNFINISHED', 2),
        'daily_generations' => (int) env('LIMIT_USER_DAILY_GENERATIONS', 25),
        // What the user pays: charges so far plus the most anything unfinished could cost.
        'daily_spend' => (string) env('LIMIT_USER_DAILY_SPEND', '2.00'),
    ],

    // Across all users: a ceiling on what Pixelforge can bill in a day.
    'app' => [
        'daily_generations' => (int) env('LIMIT_APP_DAILY_GENERATIONS', 200),
        // Pixelforge's cost so far plus the most anything unfinished could cost.
        'daily_spend' => (string) env('LIMIT_APP_DAILY_SPEND', '10.00'),
    ],

    // Generate requests per user per minute.
    'submits_per_minute' => (int) env('LIMIT_SUBMITS_PER_MINUTE', 6),

    // New accounts per IP address.
    'registrations' => [
        'per_hour' => (int) env('LIMIT_REGISTRATIONS_PER_HOUR', 3),
        'per_day' => (int) env('LIMIT_REGISTRATIONS_PER_DAY', 10),
    ],

];
