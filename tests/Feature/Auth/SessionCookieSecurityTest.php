<?php

use App\Models\Customer;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Auth\MagicLinkService;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * @param  list<string>  $rememberPrefixes
 */
function assertAuthenticationCookiesAreSecure(TestResponse $response, array $rememberPrefixes): void
{
    $cookies = collect($response->headers->getCookies());
    $authenticationCookies = $cookies->filter(function (Cookie $cookie) use ($rememberPrefixes): bool {
        if ($cookie->getName() === config('session.cookie')) {
            return true;
        }

        return collect($rememberPrefixes)->contains(
            fn (string $prefix): bool => str_starts_with($cookie->getName(), $prefix)
        );
    })->values();

    expect($authenticationCookies)->toHaveCount(2);

    $authenticationCookies->each(function (Cookie $cookie): void {
        expect($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    });
}

/**
 * @param  list<string>  $rememberPrefixes
 */
function assertMagicLinkCreatesOnlyASecureSession(TestResponse $response, array $rememberPrefixes): void
{
    $cookies = collect($response->headers->getCookies());
    $sessionCookie = $cookies->first(
        fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie')
    );

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isSecure())->toBeTrue()
        ->and($sessionCookie->isHttpOnly())->toBeTrue()
        ->and($sessionCookie->getSameSite())->toBe('lax');

    $rememberCookie = $cookies->first(function (Cookie $cookie) use ($rememberPrefixes): bool {
        return collect($rememberPrefixes)->contains(
            fn (string $prefix): bool => str_starts_with($cookie->getName(), $prefix)
        );
    });

    expect($rememberCookie)->toBeNull();
}

test('an https application secures staff cookies independently of the backend request scheme', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => null,
    ]);

    try {
        (new AppServiceProvider(app()))->boot();

        $user = User::factory()->create([
            'email' => 'secure-staff@example.test',
            'password' => bcrypt('correct-password'),
        ]);

        $response = post(route('login', absolute: false), [
            'email' => $user->email,
            'password' => 'correct-password',
            'remember' => true,
        ]);

        $response->assertRedirect(route('agent.dashboard'));
        assertAuthenticationCookiesAreSecure($response, ['remember_web_']);
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});

test('an https customer magic link creates a secure session without a remember cookie', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => null,
    ]);

    try {
        (new AppServiceProvider(app()))->boot();

        $customer = Customer::factory()->create();
        $magicLink = app(MagicLinkService::class)->issueCustomer($customer);
        $verifyUrl = URL::temporarySignedRoute(
            'customer.auth.verify',
            $magicLink['expires_at'],
            ['customer' => $customer->id, 'token' => $magicLink['token']]
        );

        $response = get($verifyUrl);

        $response->assertRedirect(route('customer.tickets.index'));
        assertMagicLinkCreatesOnlyASecureSession($response, ['remember_customer_']);
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});

test('an https staff magic link creates a secure session without a remember cookie', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => null,
    ]);

    try {
        (new AppServiceProvider(app()))->boot();

        $user = User::factory()->create();
        $magicLink = app(MagicLinkService::class)->issueStaff($user);

        expect($magicLink)->not->toBeNull();

        $verifyUrl = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            $magicLink['expires_at'],
            ['user' => $user->id, 'token' => $magicLink['token']]
        );

        $response = get($verifyUrl);

        $response->assertRedirect(route('agent.tickets.index'));
        assertMagicLinkCreatesOnlyASecureSession($response, ['remember_web_']);
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});
