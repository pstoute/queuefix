<?php

use App\Mail\CustomerMagicLinkMail;
use App\Mail\MagicLinkMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('staff magic links are limited per recipient across source addresses', function () {
    User::factory()->create(['email' => 'agent@example.com']);

    foreach (range(1, 3) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
            ->post(route('auth.magic-link.send'), ['email' => 'agent@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with that email, a magic link has been sent.');
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post(route('auth.magic-link.send'), ['email' => 'agent@example.com']);

    $response->assertTooManyRequests()->assertHeader('Retry-After');
    Mail::assertSent(MagicLinkMail::class, 3);
});

test('unknown staff identities have the same response and recipient limit', function () {
    foreach (range(1, 3) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
            ->post(route('auth.magic-link.send'), ['email' => 'missing@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with that email, a magic link has been sent.');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post(route('auth.magic-link.send'), ['email' => 'missing@example.com'])
        ->assertTooManyRequests();

    Mail::assertNothingSent();
});

test('recipient limits are shared across staff and customer magic links', function () {
    User::factory()->create(['email' => 'shared@example.com']);

    foreach (range(1, 2) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
            ->post(route('auth.magic-link.send'), ['email' => 'shared@example.com'])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])
        ->post(route('customer.login.send'), ['email' => 'shared@example.com'])
        ->assertRedirect();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post(route('customer.login.send'), ['email' => 'SHARED@example.com'])
        ->assertTooManyRequests();

    Mail::assertSent(MagicLinkMail::class, 2);
    Mail::assertSent(CustomerMagicLinkMail::class, 1);
    expect(Customer::where('email', 'shared@example.com')->count())->toBe(1);
});

test('customer magic links are bounded per source before creating customers', function () {
    foreach (range(1, 20) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post(route('customer.login.send'), [
                'email' => "customer{$requestNumber}@example.com",
            ])
            ->assertRedirect();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->post(route('customer.login.send'), ['email' => 'blocked@example.com']);

    $response->assertTooManyRequests()->assertHeader('Retry-After');
    expect(Customer::count())->toBe(20);
    $this->assertDatabaseMissing('customers', ['email' => 'blocked@example.com']);
    Mail::assertSent(CustomerMagicLinkMail::class, 20);
});

test('a different source and recipient remain available after another source is limited', function () {
    foreach (range(1, 20) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('customer.login.send'), [
                'email' => "first-source-{$requestNumber}@example.com",
            ])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
        ->post(route('customer.login.send'), ['email' => 'other-source@example.com'])
        ->assertRedirect();

    $this->assertDatabaseHas('customers', ['email' => 'other-source@example.com']);
    Mail::assertSent(CustomerMagicLinkMail::class, 21);
});

test('untrusted forwarded addresses cannot rotate the source limit', function () {
    foreach (range(1, 20) as $requestNumber) {
        $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$requestNumber}"])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
            ->post(route('customer.login.send'), [
                'email' => "forwarded-{$requestNumber}@example.com",
            ])
            ->assertRedirect();
    }

    $this->withHeaders(['X-Forwarded-For' => '203.0.113.250'])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
        ->post(route('customer.login.send'), ['email' => 'blocked-forwarded@example.com'])
        ->assertTooManyRequests();
});

test('an explicitly trusted proxy separates forwarded client source limits', function () {
    TrustProxies::at(['198.51.100.40']);

    try {
        foreach (range(1, 20) as $requestNumber) {
            $this->withHeaders(['X-Forwarded-For' => '203.0.113.40'])
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
                ->post(route('customer.login.send'), [
                    'email' => "proxied-{$requestNumber}@example.com",
                ])
                ->assertRedirect();
        }

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.40'])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->post(route('customer.login.send'), ['email' => 'blocked-proxied@example.com'])
            ->assertTooManyRequests();

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.41'])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->post(route('customer.login.send'), ['email' => 'other-proxied-client@example.com'])
            ->assertRedirect();
    } finally {
        TrustProxies::at([]);
    }

    $this->assertDatabaseMissing('customers', ['email' => 'blocked-proxied@example.com']);
    $this->assertDatabaseHas('customers', ['email' => 'other-proxied-client@example.com']);
});

test('recipient limit resets after fifteen minutes', function () {
    User::factory()->create(['email' => 'reset@example.com']);

    foreach (range(1, 3) as $requestNumber) {
        $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$requestNumber}"])
            ->post(route('auth.magic-link.send'), ['email' => 'reset@example.com'])
            ->assertRedirect();
    }

    $this->travel(15)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->post(route('auth.magic-link.send'), ['email' => 'reset@example.com'])
        ->assertRedirect();

    Mail::assertSent(MagicLinkMail::class, 4);
});
