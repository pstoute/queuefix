<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\StaffAuthenticationRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create()->fresh();
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

test('deactivating a user revokes all of their authentication state', function () {
    actingAs($this->admin);

    $user = User::factory()->create([
        'remember_token' => 'captured-remember-token',
    ]);
    $unrelatedUser = User::factory()->create();

    DB::table('sessions')->insert([
        [
            'id' => 'target-session',
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'QueueFix test',
            'payload' => 'serialized-session-payload',
            'last_activity' => now()->getTimestamp(),
        ],
        [
            'id' => 'unrelated-session',
            'user_id' => $unrelatedUser->id,
            'ip_address' => '192.0.2.11',
            'user_agent' => 'QueueFix test',
            'payload' => 'serialized-session-payload',
            'last_activity' => now()->getTimestamp(),
        ],
    ]);

    app(MagicLinkService::class)->issueStaff($user);
    $resetToken = Password::broker()->createToken($user);

    put(route('settings.users.update', $user), [
        'is_active' => false,
    ])->assertRedirect();

    $user->refresh();

    expect($user->is_active)->toBeFalse()
        ->and($user->authentication_version)->toBe(1)
        ->and($user->remember_token)->not->toBe('captured-remember-token')
        ->and(DB::table('sessions')->where('id', 'target-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'unrelated-session')->exists())->toBeTrue()
        ->and(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists())->toBeFalse()
        ->and(Password::broker()->tokenExists($user, $resetToken))->toBeFalse();
});

test('reactivating a legacy inactive user revokes authentication state before enabling access', function () {
    actingAs($this->admin);

    $user = User::factory()->create([
        'remember_token' => 'legacy-remember-token',
    ]);
    DB::table('sessions')->insert([
        'id' => 'legacy-session',
        'user_id' => $user->id,
        'ip_address' => '192.0.2.10',
        'user_agent' => 'QueueFix test',
        'payload' => 'serialized-session-payload',
        'last_activity' => now()->getTimestamp(),
    ]);
    app(MagicLinkService::class)->issueStaff($user);
    $resetToken = Password::broker()->createToken($user);
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    put(route('settings.users.update', $user), [
        'is_active' => true,
    ])->assertRedirect();

    $user->refresh();

    expect($user->is_active)->toBeTrue()
        ->and($user->authentication_version)->toBe(1)
        ->and($user->remember_token)->not->toBe('legacy-remember-token')
        ->and(DB::table('sessions')->where('id', 'legacy-session')->exists())->toBeFalse()
        ->and(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists())->toBeFalse()
        ->and(Password::broker()->tokenExists($user, $resetToken))->toBeFalse();
});

test('revocation failure rolls back the staff deactivation and credential changes', function () {
    actingAs($this->admin);

    $user = User::factory()->create([
        'name' => 'Original Name',
        'remember_token' => 'original-remember-token',
    ]);
    DB::table('sessions')->insert([
        'id' => 'target-session',
        'user_id' => $user->id,
        'ip_address' => '192.0.2.10',
        'user_agent' => 'QueueFix test',
        'payload' => 'serialized-session-payload',
        'last_activity' => now()->getTimestamp(),
    ]);
    app(MagicLinkService::class)->issueStaff($user);
    $resetToken = Password::broker()->createToken($user);
    $realRevoker = app(StaffAuthenticationRevocationService::class);

    $this->mock(
        StaffAuthenticationRevocationService::class,
        fn (MockInterface $mock) => $mock->shouldReceive('revokeAll')
            ->once()
            ->andReturnUsing(function (User $revokedUser) use ($realRevoker): never {
                $realRevoker->revokeAll($revokedUser);

                throw new \RuntimeException('revocation failed');
            })
    );

    $this->withoutExceptionHandling();

    try {
        put(route('settings.users.update', $user), [
            'name' => 'Changed Name',
            'is_active' => false,
        ]);

        $this->fail('Expected the revocation failure to abort deactivation.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->toBe('revocation failed');
    }

    $this->withExceptionHandling();
    $user->refresh();

    expect($user->name)->toBe('Original Name')
        ->and($user->is_active)->toBeTrue()
        ->and($user->authentication_version)->toBe(0)
        ->and($user->remember_token)->toBe('original-remember-token')
        ->and(DB::table('sessions')->where('id', 'target-session')->exists())->toBeTrue()
        ->and(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists())->toBeTrue()
        ->and(Password::broker()->tokenExists($user, $resetToken))->toBeTrue();
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
