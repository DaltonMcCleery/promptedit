<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, string>  $overrides
 */
function registerAccount(string $email, string $ip = '203.0.113.7', array $overrides = []): TestResponse
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register.store'), [
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ]);

    Auth::logout();

    return $response;
}

it('refuses a sign-up that fills in the hidden field', function () {
    registerAccount('bot@example.com', overrides: [CreateNewUser::HONEYPOT => 'https://spam.example'])
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});

it('limits new accounts per IP address per hour', function () {
    config(['limits.registrations.per_hour' => 2]);

    registerAccount('one@example.com')->assertSessionHasNoErrors();
    registerAccount('two@example.com')->assertSessionHasNoErrors();
    registerAccount('three@example.com')->assertSessionHasErrors('email');
    registerAccount('elsewhere@example.com', ip: '198.51.100.9')->assertSessionHasNoErrors();

    expect(User::pluck('email')->sort()->values()->all())
        ->toBe(['elsewhere@example.com', 'one@example.com', 'two@example.com']);
});

it('limits new accounts per IP address per day', function () {
    config(['limits.registrations.per_hour' => 10, 'limits.registrations.per_day' => 1]);

    registerAccount('one@example.com')->assertSessionHasNoErrors();

    $this->travel(2)->hours();

    registerAccount('two@example.com')->assertSessionHasErrors('email');
    expect(User::count())->toBe(1);
});

it("doesn't count sign-ups that fail validation", function () {
    config(['limits.registrations.per_hour' => 1]);

    registerAccount('typo@example.com', overrides: ['password_confirmation' => 'different'])->assertSessionHasErrors('password');
    registerAccount('fixed@example.com')->assertSessionHasNoErrors();

    expect(User::count())->toBe(1);
});
