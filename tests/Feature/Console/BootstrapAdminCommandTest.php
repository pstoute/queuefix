<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('the bootstrap command creates one unique administrator', function () {
    $this->artisan('queuefix:bootstrap-admin', [
        '--name' => 'QueueFix Owner',
        '--email' => 'Owner@Example.com',
    ])
        ->expectsQuestion('Administrator password', 'QueueFix-Owner-2026!')
        ->expectsQuestion('Confirm administrator password', 'QueueFix-Owner-2026!')
        ->expectsOutput('Administrator created.')
        ->assertSuccessful();

    $admin = User::query()->sole();

    expect($admin->name)->toBe('QueueFix Owner')
        ->and($admin->email)->toBe('owner@example.com')
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->password)->not->toBe('QueueFix-Owner-2026!')
        ->and(Hash::check('QueueFix-Owner-2026!', $admin->password))->toBeTrue();
});

test('the bootstrap command rejects weak or mismatched passwords', function (string $password, string $confirmation) {
    $this->artisan('queuefix:bootstrap-admin', [
        '--name' => 'QueueFix Owner',
        '--email' => 'owner@example.com',
    ])
        ->expectsQuestion('Administrator password', $password)
        ->expectsQuestion('Confirm administrator password', $confirmation)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'published demo password' => ['password', 'password'],
    'short demo password' => ['demo', 'demo'],
    'mismatched confirmation' => ['QueueFix-Owner-2026!', 'QueueFix-Other-2026!'],
]);

test('the bootstrap command is disabled in demo mode', function () {
    config(['demo.enabled' => true]);

    $this->artisan('queuefix:bootstrap-admin', [
        '--name' => 'QueueFix Owner',
        '--email' => 'owner@example.com',
    ])
        ->expectsOutput('Administrator bootstrap is disabled in demo mode.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('the bootstrap command refuses an initialized installation without the legacy administrator', function () {
    User::factory()->create();

    $this->artisan('queuefix:bootstrap-admin', [
        '--name' => 'QueueFix Owner',
        '--email' => 'owner@example.com',
    ])
        ->expectsOutput('Administrator bootstrap is only available on an empty installation or to rotate the legacy default administrator.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

test('the bootstrap command rotates the legacy administrator without changing its identity', function () {
    $legacyAdmin = User::factory()->admin()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'remember_token' => 'legacy-remember-token',
    ]);
    DB::table('sessions')->insert([
        'id' => 'legacy-session',
        'user_id' => $legacyAdmin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('queuefix:bootstrap-admin', [
        '--name' => 'QueueFix Owner',
        '--email' => 'owner@example.com',
    ])
        ->expectsQuestion('Administrator password', 'QueueFix-Rotated-2026!')
        ->expectsQuestion('Confirm administrator password', 'QueueFix-Rotated-2026!')
        ->expectsOutput('Legacy administrator credential rotated.')
        ->assertSuccessful();

    $rotatedAdmin = $legacyAdmin->fresh();

    expect(User::query()->count())->toBe(1)
        ->and($rotatedAdmin->id)->toBe($legacyAdmin->id)
        ->and($rotatedAdmin->email)->toBe('owner@example.com')
        ->and($rotatedAdmin->remember_token)->toBeNull()
        ->and(Hash::check('password', $rotatedAdmin->password))->toBeFalse()
        ->and(Hash::check('QueueFix-Rotated-2026!', $rotatedAdmin->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $legacyAdmin->id)->exists())->toBeFalse();
});
