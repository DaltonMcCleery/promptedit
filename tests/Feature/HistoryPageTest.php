<?php

use App\Generations\PendingReason;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to sign in', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

it('shows only the signed-in user\'s generations, newest first', function () {
    $user = User::factory()->create();
    $older = Generation::factory()->for($user)->succeeded()->create(['created_at' => now()->subHour()]);
    $newer = Generation::factory()->for($user)->queued()->create(['created_at' => now()]);
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.0.status', 'queued')
            ->where('generations.data.0.is_final', false)
            ->where('generations.data.1.id', $older->id)
            ->where('generations.data.1.charge_nanos', $older->charge_nanos));
});

it('pages 20 generations at a time', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(21)->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 20)
            ->where('generations.meta.last_page', 2));
});

it('totals the charges over all pages, counting succeeded generations only', function () {
    $user = User::factory()->create();
    $succeeded = Generation::factory()->for($user)->succeeded()->count(22)->create();
    Generation::factory()->for($user)->failed()->count(3)->create();
    Generation::factory()->for($user)->processing()->create();
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.charge_nanos', $succeeded->sum('charge_nanos'))
            ->where('totals.succeeded_count', 22));
});

it('gives failed generations a plain-language reason and $0', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->failed('internal_error')->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'failed')
            ->where('generations.data.0.charge_nanos', 0)
            ->where('generations.data.0.failure_reason', 'Something went wrong at Pixelforge. Trying again usually works.'));
});

it('tells the page why a generation is still submitting', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create(['pending_reason' => PendingReason::WaitingForSlot]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'submitting')
            ->where('generations.data.0.pending_reason', 'waiting_for_slot')
            ->where('generations.data.0.failure_reason', null));
});
