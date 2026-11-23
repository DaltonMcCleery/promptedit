<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('redirects guests to sign in', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
});

it('gives the page Pixelforge pricing as integers for the live estimate', function () {
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());

    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->where('pricing.rates.output_tokens', 40_000_000_000)
            ->where('pricing.characters_per_text_token', 4)
            ->where('pricing.text_token_variance_basis_points', 1500)
            ->where('pricing.tokens_per_reference_image', 765)
            ->where('pricing.output_tokens.high.1536x1024', 6450)
            ->where('pricing.reasoning_tokens.high.max', 8000)
            ->where('initial', null));
});

it('passes no pricing when Pixelforge prices are unavailable', function () {
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => pixelforgeError(503, 'overloaded')]);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pricing', null));
});

it('prefills the form from one of the user\'s generations to try again', function () {
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $user = User::factory()->create();
    $failed = Generation::factory()->for($user)->failed()->create([
        'prompt' => 'A red bicycle [fail]',
        'reference_images' => ['https://example.com/a.png'],
    ]);

    $this->actingAs($user)
        ->get(route('generations.create', ['from' => $failed->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('initial.prompt', 'A red bicycle [fail]')
            ->where('initial.size', '1024x1024')
            ->where('initial.quality', 'medium')
            ->where('initial.reference_images', ['https://example.com/a.png']));
});

it('will not prefill from another user\'s generation', function () {
    Cache::put(PricingRepository::CACHE_KEY, pixelforgePricing());
    $someoneElses = Generation::factory()->failed()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('generations.create', ['from' => $someoneElses->id]))
        ->assertNotFound();
});
