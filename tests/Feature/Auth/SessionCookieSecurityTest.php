<?php

use App\Models\Customer;
use App\Models\User;
use App\Providers\AppServiceProvider;
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

test('an https application secures customer session and remember cookies', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => null,
    ]);

    try {
        (new AppServiceProvider(app()))->boot();

        $customer = Customer::factory()->create();
        $verifyUrl = URL::temporarySignedRoute(
            'customer.auth.verify',
            now()->addMinutes(15),
            ['customer' => $customer->id]
        );

        $response = get($verifyUrl);

        $response->assertRedirect(route('customer.tickets.index'));
        assertAuthenticationCookiesAreSecure($response, ['remember_customer_']);
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});
