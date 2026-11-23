<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('the dashboard sends signed-in users to the generate page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('generations.create'));
});

test('users who never verified their email can reach the generate page', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('generations.create'));
});

test('the home page sends signed-in users to the generate page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertRedirect(route('generations.create'));
});
