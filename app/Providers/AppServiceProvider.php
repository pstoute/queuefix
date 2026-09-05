<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
