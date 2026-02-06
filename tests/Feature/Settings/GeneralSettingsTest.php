<?php

use App\Models\Setting;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, put};

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('settings page renders', function () {
    actingAs($this->admin);

    get(route('settings.general.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/General')
        );
});

test('updating settings', function () {
    actingAs($this->admin);

    put(route('settings.general.update'), [
        'app_name' => 'Acme Corporation',
        'app_url' => 'https://acme.example.com',
        'timezone' => 'America/New_York',
        'default_language' => 'en',
        'ticket_prefix' => 'QF',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('settings', [
        'key' => 'app_name',
        'value' => 'Acme Corporation',
    ]);
});

test('unauthenticated users cannot access settings', function () {
    get(route('settings.general.index'))
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});
