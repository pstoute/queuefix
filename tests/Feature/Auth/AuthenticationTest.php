<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;
use function Pest\Laravel\{get, post};

test('login page renders', function () {
    get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

test('login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    post(route('login'), [
        'email' => 'test@example.com',
        'password' => 'password',
    ])
        ->assertRedirect(route('agent.dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('login with invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    post(route('login'), [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('oauth redirect for google', function () {
    get(route('auth.social.redirect', ['provider' => 'google']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('oauth redirect for microsoft', function () {
    get(route('auth.social.redirect', ['provider' => 'microsoft']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('magic link form renders', function () {
    get(route('auth.magic-link'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/MagicLink'));
});

test('magic link sending', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    post(route('auth.magic-link.send'), [
        'email' => 'test@example.com',
    ])
        ->assertRedirect()
        ->assertSessionHas('status');
});

test('magic link verification with valid signature', function () {
    $user = User::factory()->create();

    $verifyUrl = URL::temporarySignedRoute(
        'auth.magic-link.verify',
        now()->addMinutes(30),
        ['user' => $user->id]
    );

    get($verifyUrl)
        ->assertRedirect(route('agent.dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('magic link verification with invalid signature', function () {
    $user = User::factory()->create();

    get(route('auth.magic-link.verify', ['user' => $user->id]))
        ->assertStatus(403);

    $this->assertGuest();
});

test('magic link verification with expired link', function () {
    $user = User::factory()->create();

    $verifyUrl = URL::temporarySignedRoute(
        'auth.magic-link.verify',
        now()->subMinutes(10), // Expired
        ['user' => $user->id]
    );

    get($verifyUrl)
        ->assertStatus(403);

    $this->assertGuest();
});
