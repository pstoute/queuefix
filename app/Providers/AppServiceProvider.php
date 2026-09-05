<?php

namespace App\Providers;

use App\Contracts\AttachmentScanner;
use App\Services\Attachments\UnavailableAttachmentScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AttachmentScanner::class, UnavailableAttachmentScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSessionCookieSecurity();

        if (config('trustedproxy.required') && config('trustedproxy.proxies') === []) {
            throw new LogicException('TRUSTED_PROXIES must contain the immediate reverse proxy when TRUSTED_PROXY_REQUIRED is enabled.');
        }

        $limiterStore = (string) config('cache.limiter');
        $limiterDriver = config("cache.stores.{$limiterStore}.driver");

        if ($this->app->environment('production')
            && ! in_array($limiterDriver, ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            throw new LogicException('RATE_LIMITER_STORE must use a persistent shared cache store in production.');
        }

        Vite::prefetch(concurrency: 3);

        Event::listen(SocialiteWasCalled::class, MicrosoftExtendSocialite::class.'@handle');

        TrustProxies::at(config('trustedproxy.proxies'));

        RateLimiter::for('magic-link', function (Request $request): array {
            $recipient = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(20)->by('source:'.$request->ip()),
                Limit::perMinutes(15, 3)->by('recipient:'.hash('sha256', $recipient)),
            ];
        });
    }

    private function configureSessionCookieSecurity(): void
    {
        $applicationScheme = strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));

        if ($applicationScheme !== 'https') {
            return;
        }

        if (config('session.secure') === false) {
            throw new LogicException('SESSION_SECURE_COOKIE cannot be false when APP_URL uses HTTPS.');
        }

        // Promote nullable legacy/config-cached values independently of the
        // request scheme so TLS termination cannot create an insecure cookie.
        config(['session.secure' => true]);
    }
}
