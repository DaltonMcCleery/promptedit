<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /** A hidden sign-up field. People never see it; form-filling bots fill it in. */
    public const string HONEYPOT = 'extra_details';

    private const int HOUR = 3600;

    private const int DAY = 86400;

    /**
     * Validate and create a newly registered user.
     *
     * Each IP address may create a few accounts an hour and a day, so one
     * person can't sign up again and again to reset the per-user limits.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if ($this->tooManyFromThisAddress()) {
            throw ValidationException::withMessages([
                'email' => 'Too many accounts have been created from your network. Please try again later.',
            ]);
        }

        if (filled($input[self::HONEYPOT] ?? null)) {
            $this->countAttempt();

            throw ValidationException::withMessages([
                'email' => "We couldn't create your account.",
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $this->countAttempt();

        return $user;
    }

    private function tooManyFromThisAddress(): bool
    {
        return RateLimiter::tooManyAttempts($this->key('hour'), (int) config('limits.registrations.per_hour'))
            || RateLimiter::tooManyAttempts($this->key('day'), (int) config('limits.registrations.per_day'));
    }

    private function countAttempt(): void
    {
        RateLimiter::hit($this->key('hour'), self::HOUR);
        RateLimiter::hit($this->key('day'), self::DAY);
    }

    private function key(string $window): string
    {
        // Read per call: Fortify keeps this action as a singleton, so an injected request could be an old one.
        return "registrations:{$window}:".request()->ip();
    }
}
