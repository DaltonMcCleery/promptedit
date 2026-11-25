<?php

use App\Models\User;

it('suspends and unsuspends a user by email', function () {
    $user = User::factory()->create(['email' => 'spammer@example.com']);

    $this->artisan('app:suspend-user', ['email' => 'spammer@example.com'])->assertSuccessful();
    expect($user->fresh()->isSuspended())->toBeTrue();

    $this->artisan('app:unsuspend-user', ['email' => 'spammer@example.com'])->assertSuccessful();
    expect($user->fresh()->isSuspended())->toBeFalse();
});

it('fails for an unknown email', function () {
    $this->artisan('app:suspend-user', ['email' => 'nobody@example.com'])->assertFailed();
    $this->artisan('app:unsuspend-user', ['email' => 'nobody@example.com'])->assertFailed();
});

it('refuses to sign in a suspended user', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account is suspended.']);

    $this->assertGuest();
});

it('still signs in other users', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('gives a wrong password the usual error, even for a suspended user', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('signs out a user suspended mid-session on their next request', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('generations.index'))->assertOk();

    $user->forceFill(['suspended_at' => now()])->save();

    $this->get(route('generations.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This account is suspended.');
    $this->assertGuest();
});
