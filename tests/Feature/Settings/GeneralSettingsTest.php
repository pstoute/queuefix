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
            ->component('Settings/General/Index')
        );
});

test('updating settings', function () {
    actingAs($this->admin);

    put(route('settings.general.update'), [
        'company_name' => 'Acme Corporation',
        'support_email' => 'support@acme.com',
        'timezone' => 'America/New_York',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('unauthenticated users cannot access settings', function () {
    get(route('settings.general.index'))
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});
