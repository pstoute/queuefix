<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\StaffAuthenticationRevocationService;
use App\Services\Auth\StaffPasswordChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_password_change_revokes_prior_authentication_state_and_preserves_only_a_fresh_current_session(): void
    {
        $this->useDatabaseSessions();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'remember_token' => 'old-remember-token',
        ]);
        $unrelatedUser = User::factory()->create();
        $originalRememberToken = $user->getRememberToken();

        DB::table('sessions')->insert([
            $this->sessionRow('target-session-one', $user->id),
            $this->sessionRow('target-session-two', $user->id),
            $this->sessionRow('unrelated-session', $unrelatedUser->id),
            $this->sessionRow('guest-session', null),
        ]);

        app(MagicLinkService::class)->issueStaff($user);
        $resetToken = Password::broker()->createToken($user);

        $sessionCookie = (string) config('session.cookie');
        $loginCookie = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'old-password',
        ])->assertRedirect(route('agent.dashboard'))->getCookie($sessionCookie);
        $this->assertNotNull($loginCookie);
        $originalSessionId = $loginCookie->getValue();
        $this->withCookie($sessionCookie, $originalSessionId);

        $response = $this->from('/profile')->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertSame(1, $user->authentication_version);
        $this->assertNotSame($originalRememberToken, $user->getRememberToken());
        $this->assertFalse(DB::table('sessions')->whereIn('id', [
            'target-session-one',
            'target-session-two',
        ])->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'unrelated-session')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'guest-session')->exists());
        $this->assertFalse(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());
        $this->assertFalse(Password::broker()->tokenExists($user, $resetToken));
        $this->assertAuthenticatedAs($user);

        $replacementCookie = $response->getCookie($sessionCookie);
        $this->assertNotNull($replacementCookie);
        $replacementSessionId = $replacementCookie->getValue();
        $this->assertNotSame($originalSessionId, $replacementSessionId);
        $this->assertDatabaseCount('sessions', 3);
        $this->assertDatabaseHas('sessions', [
            'id' => $replacementSessionId,
            'user_id' => $user->id,
        ]);
        $this->assertSame(
            $user->authentication_version,
            session()->get(StaffAuthenticationRevocationService::SESSION_VERSION_KEY),
        );

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withCookie($sessionCookie, $replacementSessionId);

        $this->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(
            $user->authentication_version,
            session()->get(StaffAuthenticationRevocationService::SESSION_VERSION_KEY),
        );
    }

    public function test_password_change_invalidates_a_captured_remember_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'remembered@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $loginResponse = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'old-password',
            'remember' => true,
        ])->assertRedirect(route('agent.dashboard'));

        $recallerName = Auth::guard('web')->getRecallerName();
        $capturedRecaller = $loginResponse->getCookie($recallerName)->getValue();

        $this->from('/profile')->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        session()->forget(Auth::guard('web')->getName());
        Auth::forgetGuards();
        $this->withCookie($recallerName, $capturedRecaller);

        $this->get(route('agent.dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_validated_before_password_change_cannot_persist_a_usable_session(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $validatedBeforeChange = User::query()->findOrFail($user->id);
        $this->assertTrue(Hash::check('old-password', $validatedBeforeChange->password));

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        Auth::login($validatedBeforeChange);
        Auth::forgetGuards();

        $this->get(route('agent.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session is no longer valid. Please sign in again.');

        $this->assertGuest();
    }

    public function test_revocation_failure_rolls_back_the_entire_password_change(): void
    {
        $this->useDatabaseSessions();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'remember_token' => 'old-remember-token',
        ]);
        $originalPassword = $user->password;
        $originalRememberToken = $user->getRememberToken();
        $originalAuthenticationVersion = $user->authentication_version;

        DB::table('sessions')->insert($this->sessionRow('target-session', $user->id));
        app(MagicLinkService::class)->issueStaff($user);
        $resetToken = Password::broker()->createToken($user);

        $sessionCookie = (string) config('session.cookie');
        $loginCookie = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'old-password',
        ])->assertRedirect(route('agent.dashboard'))->getCookie($sessionCookie);
        $this->assertNotNull($loginCookie);
        $originalSessionId = $loginCookie->getValue();
        $this->withCookie($sessionCookie, $originalSessionId);
        $this->assertDatabaseHas('sessions', [
            'id' => $originalSessionId,
            'user_id' => $user->id,
        ]);
        $realRevoker = app(StaffAuthenticationRevocationService::class);

        $this->mock(
            StaffAuthenticationRevocationService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('revokeAll')
                ->once()
                ->andReturnUsing(function (User $revokedUser) use ($realRevoker): never {
                    $realRevoker->revokeAll($revokedUser);

                    throw new RuntimeException('revocation failed');
                })
        );

        $this->withoutExceptionHandling();

        try {
            $this->from('/profile')->put('/password', [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $this->fail('Expected the revocation failure to abort the password change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('revocation failed', $exception->getMessage());
        }

        $this->withExceptionHandling();
        $user->refresh();

        $this->assertSame($originalPassword, $user->password);
        $this->assertSame($originalRememberToken, $user->getRememberToken());
        $this->assertSame($originalAuthenticationVersion, $user->authentication_version);
        $this->assertTrue(DB::table('sessions')->where('id', 'target-session')->exists());
        $this->assertTrue(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());
        $this->assertTrue(Password::broker()->tokenExists($user, $resetToken));
        $this->assertSame($originalSessionId, session()->getId());
        $this->assertDatabaseHas('sessions', [
            'id' => $originalSessionId,
            'user_id' => $user->id,
        ]);
        $this->assertAuthenticatedAs($user);

        $this->app->forgetInstance(StaffAuthenticationRevocationService::class);
        $this->app->forgetInstance(StaffPasswordChangeService::class);
        Route::getRoutes()->getByName('password.update')?->flushController();

        $response = $this->from('/profile')->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(DB::table('sessions')->whereIn('id', [
            'target-session',
            $originalSessionId,
        ])->exists());
        $this->assertFalse(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());
        $this->assertFalse(Password::broker()->tokenExists($user, $resetToken));

        $replacementCookie = $response->getCookie($sessionCookie);
        $this->assertNotNull($replacementCookie);
        $replacementSessionId = $replacementCookie->getValue();
        $this->assertNotSame($originalSessionId, $replacementSessionId);
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseHas('sessions', [
            'id' => $replacementSessionId,
            'user_id' => $user->id,
        ]);

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->withCookie($sessionCookie, $replacementSessionId);

        $this->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'remember_token' => 'old-remember-token',
        ]);
        app(MagicLinkService::class)->issueStaff($user);
        $resetToken = Password::broker()->createToken($user);
        $originalPassword = $user->password;

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame('old-remember-token', $user->getRememberToken());
        $this->assertSame(0, $user->authentication_version);
        $this->assertTrue(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());
        $this->assertTrue(Password::broker()->tokenExists($user, $resetToken));
    }

    /**
     * @return array{id: string, user_id: string|null, ip_address: string, user_agent: string, payload: string, last_activity: int}
     */
    private function sessionRow(string $id, ?string $userId): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'QueueFix test',
            'payload' => 'serialized-session-payload',
            'last_activity' => now()->getTimestamp(),
        ];
    }

    private function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
    }
}
