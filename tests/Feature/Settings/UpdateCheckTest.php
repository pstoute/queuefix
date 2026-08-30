<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('admins can view the installed version and latest published release', function () {
    Http::fake([
        'https://api.github.com/repos/pstoute/queuefix/releases/latest' => Http::response([
            'tag_name' => 'v1.2.0',
            'html_url' => 'https://github.com/pstoute/queuefix/releases/tag/v1.2.0',
            'published_at' => '2026-08-30T12:00:00Z',
            'body' => 'Security fixes.',
        ]),
    ]);

    actingAs($this->admin);

    get(route('settings.updates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Updates')
            ->where('updateCheck.installedVersion', 'v1.1.0')
            ->where('updateCheck.latestVersion', 'v1.2.0')
            ->where('updateCheck.updateAvailable', true)
            ->where('updateCheck.releaseUrl', 'https://github.com/pstoute/queuefix/releases/tag/v1.2.0')
        );
});

test('the update check uses a cached release result', function () {
    Cache::put('queuefix.latest-release', [
        'version' => 'v1.2.0',
        'url' => 'https://github.com/pstoute/queuefix/releases/tag/v1.2.0',
        'publishedAt' => '2026-08-30T12:00:00Z',
        'notes' => 'Security fixes.',
    ], now()->addDay());
    Http::preventStrayRequests();

    actingAs($this->admin);

    get(route('settings.updates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('updateCheck.latestVersion', 'v1.2.0')
            ->where('updateCheck.updateAvailable', true)
        );
});

test('the public version endpoint only exposes the installed version', function () {
    get(route('version.show'))
        ->assertOk()
        ->assertExactJson(['version' => 'v1.1.0']);
});

test('agents cannot access administrative settings', function () {
    actingAs(User::factory()->create(['role' => UserRole::Agent]));

    get(route('settings.general.index'))->assertForbidden();
    get(route('settings.updates.index'))->assertForbidden();
});
