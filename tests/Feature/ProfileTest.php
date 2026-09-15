<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $user = User::factory()->create();

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
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

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
