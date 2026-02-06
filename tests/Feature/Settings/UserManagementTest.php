<?php

use App\Enums\UserRole;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, put};

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('listing users', function () {
    actingAs($this->admin);

    User::factory()->count(5)->create();

    get(route('settings.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Users/Index')
            ->has('users')
        );
});

test('inviting a user', function () {
    actingAs($this->admin);

    post(route('settings.users.store'), [
        'name' => 'New Agent',
        'email' => 'agent@example.com',
        'role' => UserRole::Agent->value,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'name' => 'New Agent',
        'email' => 'agent@example.com',
        'role' => UserRole::Agent->value,
    ]);
});

test('inviting admin user', function () {
    actingAs($this->admin);

    post(route('settings.users.store'), [
        'name' => 'New Admin',
        'email' => 'admin@example.com',
        'role' => UserRole::Admin->value,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'name' => 'New Admin',
        'email' => 'admin@example.com',
        'role' => UserRole::Admin->value,
    ]);
});

test('updating user role', function () {
    actingAs($this->admin);

    $user = User::factory()->create(['role' => UserRole::Agent]);

    put(route('settings.users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::Admin->value,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'role' => UserRole::Admin->value,
    ]);
});

test('user email must be unique', function () {
    actingAs($this->admin);

    User::factory()->create(['email' => 'existing@example.com']);

    post(route('settings.users.store'), [
        'name' => 'Test User',
        'email' => 'existing@example.com',
        'role' => UserRole::Agent->value,
    ])
        ->assertSessionHasErrors('email');
});

test('user name is required', function () {
    actingAs($this->admin);

    post(route('settings.users.store'), [
        'email' => 'test@example.com',
        'role' => UserRole::Agent->value,
    ])
        ->assertSessionHasErrors('name');
});

test('user email is required', function () {
    actingAs($this->admin);

    post(route('settings.users.store'), [
        'name' => 'Test User',
        'role' => UserRole::Agent->value,
    ])
        ->assertSessionHasErrors('email');
});

test('user role is required', function () {
    actingAs($this->admin);

    post(route('settings.users.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ])
        ->assertSessionHasErrors('role');
});
