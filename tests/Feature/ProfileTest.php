<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\StaffAccountLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create([
            'email' => 'former@example.com',
            'remember_token' => 'captured-remember-token',
        ]);
        app(MagicLinkService::class)->issueStaff($user);
        $resetToken = Password::broker()->createToken($user);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(1, $user->authentication_version);
        $this->assertNotSame('captured-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('magic_link_tokens', ['authenticatable_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'former@example.com']);
        $this->assertFalse(Password::broker()->tokenExists($user, $resetToken));
        $this->get('/profile')->assertOk();
    }

    public function test_current_password_is_required_to_change_the_email_address(): void
    {
        $user = User::factory()->create();
        $originalName = $user->name;
        $originalEmail = $user->email;
        $originalVerificationDate = $user->email_verified_at;

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Attacker Controlled',
                'email' => 'attacker@example.com',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalEmail, $user->email);
        $this->assertEquals($originalVerificationDate, $user->email_verified_at);
    }

    public function test_current_password_must_be_correct_to_change_the_email_address(): void
    {
        $user = User::factory()->create();
        $originalName = $user->name;
        $originalEmail = $user->email;
        $originalVerificationDate = $user->email_verified_at;

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Attacker Controlled',
                'email' => 'attacker@example.com',
                'current_password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalEmail, $user->email);
        $this->assertEquals($originalVerificationDate, $user->email_verified_at);
    }

    public function test_malformed_current_password_is_rejected_without_an_error_response(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Attacker Controlled',
                'email' => 'attacker@example.com',
                'current_password' => ['password'],
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $this->assertNotSame('attacker@example.com', $user->refresh()->email);
    }

    public function test_profile_updates_are_limited_per_account_across_source_addresses(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this
                ->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                ->from('/profile')
                ->patch('/profile', [
                    'name' => $user->name,
                    'email' => 'attacker@example.com',
                    'current_password' => 'wrong-password',
                ])
                ->assertSessionHasErrors('current_password')
                ->assertRedirect('/profile');
        }

        $this
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'attacker@example.com',
                'current_password' => 'password',
            ])
            ->assertTooManyRequests();

        $this->assertNotSame('attacker@example.com', $user->refresh()->email);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create(['remember_token' => 'preserved-remember-token']);
        app(MagicLinkService::class)->issueStaff($user);
        $resetToken = Password::broker()->createToken($user);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->authentication_version);
        $this->assertSame('preserved-remember-token', $user->remember_token);
        $this->assertDatabaseHas('magic_link_tokens', ['authenticatable_id' => $user->id]);
        $this->assertTrue(Password::broker()->tokenExists($user, $resetToken));
    }

    public function test_profile_email_change_clears_recovery_state_inherited_from_the_destination(): void
    {
        $formerOwner = User::factory()->create(['email' => 'replacement@example.com']);
        $staleToken = Password::broker()->createToken($formerOwner);
        $formerOwner->delete();

        $user = User::factory()->create(['email' => 'current@example.com']);

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'replacement@example.com',
                'current_password' => 'password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors();

        Auth::logout();
        $this->app['session']->invalidate();
        Auth::forgetGuards();

        $this->post('/reset-password', [
            'token' => $staleToken,
            'email' => 'replacement@example.com',
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_failed_identity_change_rolls_back_revocation_and_recovery_cleanup(): void
    {
        $user = User::factory()->create([
            'email' => 'current@example.com',
            'remember_token' => 'preserved-remember-token',
        ]);
        $target = User::factory()->create(['email' => 'occupied@example.com']);
        app(MagicLinkService::class)->issueStaff($user);
        $userResetToken = Password::broker()->createToken($user);
        $targetResetToken = Password::broker()->createToken($target);

        try {
            app(StaffAccountLifecycleService::class)->updateProfile(
                $user,
                ['name' => 'Changed Name', 'email' => $target->email],
                'password',
            );

            $this->fail('The duplicate identity change unexpectedly succeeded.');
        } catch (QueryException) {
            // The database uniqueness failure must roll back every earlier revocation mutation.
        }

        $user->refresh();
        $target->refresh();

        $this->assertSame('current@example.com', $user->email);
        $this->assertNotSame('Changed Name', $user->name);
        $this->assertSame(0, $user->authentication_version);
        $this->assertSame('preserved-remember-token', $user->remember_token);
        $this->assertDatabaseHas('magic_link_tokens', ['authenticatable_id' => $user->id]);
        $this->assertTrue(Password::broker()->tokenExists($user, $userResetToken));
        $this->assertTrue(Password::broker()->tokenExists($target, $targetResetToken));
    }

    public function test_reset_token_for_a_former_email_cannot_reset_its_replacement_account(): void
    {
        $user = User::factory()->create([
            'email' => 'former@example.com',
        ]);
        $token = Password::broker()->createToken($user);

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'replacement@example.com',
                'current_password' => 'password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors();

        Auth::logout();
        $this->app['session']->invalidate();
        Auth::forgetGuards();

        $replacement = User::factory()->create([
            'email' => 'former@example.com',
            'password' => Hash::make('replacement-password'),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'former@example.com',
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('replacement-password', $replacement->fresh()->password));
    }

    public function test_reset_token_for_a_deleted_account_cannot_reset_a_replacement_account(): void
    {
        $user = User::factory()->create([
            'email' => 'released@example.com',
        ]);
        $token = Password::broker()->createToken($user);

        $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        $replacement = User::factory()->create([
            'email' => 'released@example.com',
            'password' => Hash::make('replacement-password'),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'released@example.com',
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('replacement-password', $replacement->fresh()->password));
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        app(MagicLinkService::class)->issueStaff($user);
        Password::broker()->createToken($user);

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
        $this->assertDatabaseMissing('magic_link_tokens', ['authenticatable_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
