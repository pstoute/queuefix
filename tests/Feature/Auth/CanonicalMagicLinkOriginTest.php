<?php

use App\Mail\CustomerMagicLinkMail;
use App\Mail\MagicLinkMail;
use App\Models\Customer;
use App\Models\User;
use App\Support\CanonicalUrlConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Mail;

test('a direct hostile host cannot request a staff magic link', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->post('http://attacker.invalid/auth/magic-link', ['email' => $user->email])
        ->assertBadRequest();

    Mail::assertNothingSent();
    $this->assertDatabaseMissing('magic_link_tokens', [
        'guard' => 'web',
        'authenticatable_id' => $user->id,
    ]);
});

test('a direct hostile host cannot request a customer magic link', function () {
    Mail::fake();
    $customer = Customer::factory()->create();

    $this->post('http://attacker.invalid/portal/login', ['email' => $customer->email])
        ->assertBadRequest();

    Mail::assertNothingSent();
    $this->assertDatabaseMissing('magic_link_tokens', [
        'guard' => 'customer',
        'authenticatable_id' => $customer->id,
    ]);
});

test('a trusted proxy cannot supply a hostile forwarded host', function () {
    Mail::fake();
    $user = User::factory()->create();
    $canonicalHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    TrustProxies::at(['192.0.2.10']);

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders([
                'Host' => $canonicalHost,
                'X-Forwarded-Host' => 'attacker.invalid',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Prefix' => '/capture',
            ])
            ->post(route('auth.magic-link.send', absolute: false), ['email' => $user->email])
            ->assertBadRequest();
    } finally {
        TrustProxies::at(config('trustedproxy.proxies'));
    }

    Mail::assertNothingSent();
});

test('accepted staff and customer requests always receive canonical links', function () {
    Mail::fake();
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $canonicalUrl = CanonicalUrlConfiguration::from(config('app.url'));
    $canonicalHost = parse_url($canonicalUrl->root, PHP_URL_HOST);
    $basePath = rtrim((string) parse_url($canonicalUrl->root, PHP_URL_PATH), '/');
    $requestOrigin = $canonicalUrl->scheme.'://'.$canonicalHost.':65530'.$basePath;

    $this->post($requestOrigin.'/auth/magic-link', ['email' => $user->email])
        ->assertRedirect();
    $this->post($requestOrigin.'/portal/login', ['email' => $customer->email])
        ->assertRedirect();

    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use ($canonicalUrl, $user): bool {
        return $mail->hasTo($user->email)
            && str_starts_with($mail->url, $canonicalUrl->root.'/auth/magic-link/verify/');
    });
    Mail::assertSent(CustomerMagicLinkMail::class, function (CustomerMagicLinkMail $mail) use ($canonicalUrl, $customer): bool {
        return $mail->hasTo($customer->email)
            && str_starts_with($mail->url, $canonicalUrl->root.'/portal/auth/verify/');
    });
});

test('trusted proxy metadata cannot rewrite an accepted magic link origin', function () {
    Mail::fake();
    $user = User::factory()->create();
    $canonicalUrl = CanonicalUrlConfiguration::from(config('app.url'));
    $canonicalHost = parse_url($canonicalUrl->root, PHP_URL_HOST);

    TrustProxies::at(['192.0.2.10']);

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders([
                'X-Forwarded-Host' => $canonicalHost,
                'X-Forwarded-Proto' => $canonicalUrl->scheme === 'https' ? 'http' : 'https',
                'X-Forwarded-Port' => '65530',
                'X-Forwarded-Prefix' => '/capture',
            ])
            ->post('http://queuefix.internal/auth/magic-link', ['email' => $user->email])
            ->assertRedirect();
    } finally {
        TrustProxies::at(config('trustedproxy.proxies'));
    }

    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use ($canonicalUrl, $user): bool {
        return $mail->hasTo($user->email)
            && str_starts_with($mail->url, $canonicalUrl->root.'/auth/magic-link/verify/');
    });
});
