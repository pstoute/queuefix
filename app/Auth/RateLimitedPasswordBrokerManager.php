<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Cache\RateLimiter;
use InvalidArgumentException;

class RateLimitedPasswordBrokerManager extends PasswordBrokerManager
{
    /**
     * Resolve an application password broker.
     */
    protected function resolve($name): RateLimitedPasswordBroker
    {
        $config = $this->getConfig($name);

        if ($config === null) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }

        return new RateLimitedPasswordBroker(
            $this->createTokenRepository($config),
            $this->app['auth']->createUserProvider($config['provider'] ?? null),
            $this->app['events'] ?? null,
            null,
            $this->app['config']->get('auth.timebox_duration', 200000),
            $this->app->make(RateLimiter::class),
        );
    }
}
