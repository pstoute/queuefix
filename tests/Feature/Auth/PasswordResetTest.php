<?php

namespace Tests\Feature\Auth;

use App\Auth\RateLimitedPasswordBroker;
use App\Mail\MagicLinkMail;
use App\Models\User;
use App\Notifications\ResetPassword;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\StaffAuthenticationRevocationService;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use LogicException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const RESPONSE_MESSAGE = 'If an account exists with that email, a password reset link has been sent.';

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_known_and_unknown_accounts_receive_the_same_public_response(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'known@example.com']);

        $knownResponse = $this->post('/forgot-password', ['email' => $user->email]);
        $knownResponse
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        $unknownResponse = $this->post('/forgot-password', ['email' => 'missing@example.com']);
        $unknownResponse
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        $this->assertSame($knownResponse->getStatusCode(), $unknownResponse->getStatusCode());
        $this->assertSame($knownResponse->headers->get('Location'), $unknownResponse->headers->get('Location'));
        Notification::assertCount(1);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_inactive_accounts_keep_the_generic_response_and_current_delivery_policy(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_broker_throttling_does_not_reveal_account_existence(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'throttled@example.com']);

        foreach ([$user->email, $user->email, 'missing@example.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertRedirect()
                ->assertSessionHas('status', self::RESPONSE_MESSAGE)
                ->assertSessionHasNoErrors();
        }

        Notification::assertCount(1);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_delivery_failures_are_reported_without_changing_the_public_response(): void
    {
        Exceptions::fake();
        $failure = new RuntimeException('mail transport unavailable');

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow($failure);

        $this->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $failure);
    }

    public function test_reset_delivery_is_queued_and_encrypted_outside_the_request(): void
    {
        Queue::fake();
        Mail::fake();

        $user = User::factory()->create(['email' => 'queued@example.com']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($user): bool {
            return $job->notification instanceof ResetPassword
                && $job->shouldBeEncrypted
                && $job->notifiables->contains(fn (User $notifiable): bool => $notifiable->is($user));
        });
        Mail::assertNothingSent();
    }

    public function test_password_reset_requests_are_limited_by_source(): void
    {
        Notification::fake();

        foreach (range(1, 20) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->post('/forgot-password', ['email' => "missing-{$requestNumber}@example.com"])
                ->assertRedirect()
                ->assertSessionHas('status', self::RESPONSE_MESSAGE);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post('/forgot-password', ['email' => 'blocked@example.com'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        Notification::assertNothingSent();
    }

    public function test_password_reset_recipient_limit_is_silent_and_uses_the_resolved_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'known-limit@example.com']);
        $this->assertInstanceOf(RateLimitedPasswordBroker::class, Password::broker());

        foreach (range(1, 3) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
                ->post('/forgot-password', ['email' => $user->email])
                ->assertRedirect()
                ->assertSessionHas('status', self::RESPONSE_MESSAGE)
                ->assertSessionHasNoErrors();

            $this->travel(61)->seconds();
        }

        $thirdNotification = Notification::sent($user, ResetPassword::class)->last();
        $this->assertInstanceOf(ResetPassword::class, $thirdNotification);
        $thirdToken = $thirdNotification->token;

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertCount(3);
        Notification::assertSentToTimes($user, ResetPassword::class, 3);

        $this->post('/reset-password', [
            'token' => $thirdToken,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }

    public function test_mysql_accent_equivalent_addresses_share_the_resolved_account_limit(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL collation regression.');
        }

        Notification::fake();

        $user = User::factory()->create(['email' => 'jose@example.com']);
        $equivalentAddresses = [
            'jose@example.com',
            'josé@example.com',
            'jòse@example.com',
            'jöse@example.com',
        ];

        foreach ($equivalentAddresses as $address) {
            $this->assertTrue(User::query()->where('email', $address)->firstOrFail()->is($user));
        }

        foreach (array_slice($equivalentAddresses, 0, 3) as $index => $address) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                ->post('/forgot-password', ['email' => $address])
                ->assertRedirect()
                ->assertSessionHas('status', self::RESPONSE_MESSAGE)
                ->assertSessionHasNoErrors();

            $this->travel(61)->seconds();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post('/forgot-password', ['email' => $equivalentAddresses[3]])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, ResetPassword::class, 3);
    }

    public function test_mysql_deseret_equivalent_addresses_share_the_resolved_account_limit(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL collation regression.');
        }

        Notification::fake();

        $user = User::factory()->create(['email' => '𐐀@example.com']);
        $equivalentAddresses = ['𐐀@example.com', '𐐁@example.com'];

        foreach ($equivalentAddresses as $address) {
            $this->assertTrue(User::query()->where('email', $address)->firstOrFail()->is($user));
        }

        foreach (range(1, 4) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
                ->post('/forgot-password', [
                    'email' => $equivalentAddresses[($requestNumber - 1) % 2],
                ])
                ->assertRedirect()
                ->assertSessionHas('status', self::RESPONSE_MESSAGE)
                ->assertSessionHasNoErrors();

            $this->travel(61)->seconds();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 3);
    }

    public function test_mysql_distinct_ligature_accounts_do_not_share_a_limit(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL collation regression.');
        }

        Notification::fake();

        $firstUser = User::factory()->create(['email' => 'ae@example.com']);
        $secondUser = User::factory()->create(['email' => 'æ@example.com']);

        foreach (range(1, 3) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
                ->post('/forgot-password', ['email' => $firstUser->email])
                ->assertRedirect();

            $this->travel(61)->seconds();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post('/forgot-password', ['email' => $secondUser->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($firstUser, ResetPassword::class, 3);
        Notification::assertSentToTimes($secondUser, ResetPassword::class);
    }

    public function test_distinct_unicode_recipient_addresses_do_not_share_a_limit(): void
    {
        Notification::fake();

        $firstUser = User::factory()->create(['email' => '用户@example.com']);
        $secondUser = User::factory()->create(['email' => '客户@example.com']);

        foreach (range(1, 3) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
                ->post('/forgot-password', ['email' => $firstUser->email])
                ->assertRedirect();

            $this->travel(61)->seconds();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post('/forgot-password', ['email' => $secondUser->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::RESPONSE_MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($firstUser, ResetPassword::class, 3);
        Notification::assertSentToTimes($secondUser, ResetPassword::class);
    }

    public function test_password_reset_source_limits_do_not_block_magic_link_fallback(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'source-fallback@example.com']);

        foreach (range(1, 20) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
                ->post('/forgot-password', ['email' => "missing-source-{$requestNumber}@example.com"])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
            ->post(route('auth.magic-link.send'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with that email, a magic link has been sent.');

        Mail::assertSent(MagicLinkMail::class, 1);
    }

    public function test_password_reset_recipient_limits_do_not_block_magic_link_fallback(): void
    {
        Notification::fake();
        Mail::fake();

        $user = User::factory()->create(['email' => 'recipient-fallback@example.com']);

        foreach (range(1, 3) as $requestNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
                ->post('/forgot-password', ['email' => $user->email])
                ->assertRedirect();

            $this->travel(61)->seconds();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 3);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post(route('auth.magic-link.send'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with that email, a magic link has been sent.');

        Mail::assertSent(MagicLinkMail::class, 1);
        Notification::assertSentToTimes($user, ResetPassword::class, 3);
    }

    public function test_malformed_email_still_returns_a_validation_error(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('status');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_reset_revokes_only_the_target_authentication_state(): void
    {
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
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(Hash::check('replacement-password', $user->password));
        $this->assertNotSame($originalRememberToken, $user->getRememberToken());
        $this->assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'unrelated-session')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'guest-session')->exists());
        $this->assertFalse(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertTrue(DB::table('sessions')->where('id', 'unrelated-session')->exists());
    }

    public function test_revocation_failure_rolls_back_the_entire_password_reset(): void
    {
        Event::fake([PasswordResetEvent::class]);

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'remember_token' => 'old-remember-token',
        ]);
        $originalPassword = $user->password;
        $originalRememberToken = $user->getRememberToken();
        $originalAuthenticationVersion = (int) $user->fresh()->authentication_version;

        DB::table('sessions')->insert($this->sessionRow('target-session', $user->id));
        app(MagicLinkService::class)->issueStaff($user);
        $token = Password::broker()->createToken($user);

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
            $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ]);

            $this->fail('Expected the revocation failure to abort password recovery.');
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
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        Event::assertNotDispatched(PasswordResetEvent::class);

        $this->app->forgetInstance(StaffAuthenticationRevocationService::class);
        Route::getRoutes()->getByName('password.store')?->flushController();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
        $this->assertFalse(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists());
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
        Event::assertDispatchedTimes(PasswordResetEvent::class, 1);
    }

    public function test_password_login_validated_before_recovery_cannot_persist_a_usable_session(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $validatedBeforeReset = User::query()->where('email', $user->email)->firstOrFail();
        $this->assertTrue(Hash::check('old-password', $validatedBeforeReset->password));

        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors();

        Auth::login($validatedBeforeReset);
        Auth::forgetGuards();

        $this->get(route('agent.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session is no longer valid. Please sign in again.');

        $this->assertGuest();
    }

    public function test_reset_rejects_a_broker_user_on_another_connection_before_mutation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'remember_token' => 'old-remember-token',
        ]);
        $token = Password::broker()->createToken($user);
        $storedHash = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

        config([
            'database.connections.password-reset-alternate' => [
                ...config('database.connections.sqlite'),
                'database' => ':memory:',
            ],
        ]);

        $resolvedUser = new AlternateConnectionPasswordResetUser;
        $resolvedUser->setRawAttributes($user->getAttributes(), true);
        $resolvedUser->exists = true;

        $broker = \Mockery::mock(PasswordBroker::class);
        $broker->shouldReceive('getUser')->once()->andReturn($resolvedUser);
        Password::shouldReceive('broker')->once()->andReturn($broker);

        $this->withoutExceptionHandling();

        try {
            $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ]);

            $this->fail('Expected a non-default user connection to abort password recovery.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Resolved staff accounts must use the default transactional database connection.',
                $exception->getMessage(),
            );
        }

        $this->withExceptionHandling();

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertSame('old-remember-token', $user->getRememberToken());
        $this->assertSame(
            $storedHash,
            DB::table('password_reset_tokens')->where('email', $user->email)->value('token'),
        );
    }

    public function test_predeployment_staff_session_is_stamped_without_being_revoked(): void
    {
        $user = User::factory()->create();

        Auth::login($user);
        session()->forget(StaffAuthenticationRevocationService::SESSION_VERSION_KEY);

        $this->get(route('agent.dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(
            0,
            session()->get(StaffAuthenticationRevocationService::SESSION_VERSION_KEY),
        );
    }

    public function test_magic_link_consumed_before_recovery_cannot_persist_a_usable_session(): void
    {
        $user = User::factory()->create();
        $validatedBeforeReset = User::query()->findOrFail($user->id);
        $magicLink = app(MagicLinkService::class)->issueStaff($validatedBeforeReset);

        $this->assertNotNull($magicLink);
        $this->assertTrue(app(MagicLinkService::class)->consumeStaff(
            $validatedBeforeReset,
            $magicLink['token'],
        ));

        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors();

        Auth::login($validatedBeforeReset);
        Auth::forgetGuards();

        $this->get(route('agent.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your session is no longer valid. Please sign in again.');

        $this->assertGuest();
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
}

class AlternateConnectionPasswordResetUser extends User
{
    protected $connection = 'password-reset-alternate';
}
