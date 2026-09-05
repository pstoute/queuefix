<?php

use App\Providers\AppServiceProvider;

test('an https application promotes a nullable secure session cookie policy', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => null,
    ]);

    try {
        (new AppServiceProvider(app()))->boot();

        expect(config('session.secure'))->toBeTrue();
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});

test('a direct http application may explicitly use non-secure session cookies', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'http://localhost:8000',
        'session.secure' => false,
    ]);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())->not->toThrow(LogicException::class);
        expect(config('session.secure'))->toBeFalse();
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});

test('an https application rejects an explicitly insecure session cookie policy', function () {
    $originalUrl = config('app.url');
    $originalSecure = config('session.secure');

    config([
        'app.url' => 'https://support.example.test',
        'session.secure' => false,
    ]);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())
            ->toThrow(LogicException::class, 'SESSION_SECURE_COOKIE cannot be false when APP_URL uses HTTPS.');
    } finally {
        config([
            'app.url' => $originalUrl,
            'session.secure' => $originalSecure,
        ]);
    }
});

test('proxy-required mode rejects an empty trusted proxy allowlist', function () {
    $originalEnvironment = app()->environment();
    $originalRequired = config('trustedproxy.required');
    $originalProxies = config('trustedproxy.proxies');

    app()->instance('env', 'local');
    config([
        'trustedproxy.required' => true,
        'trustedproxy.proxies' => [],
    ]);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())
            ->toThrow(LogicException::class, 'TRUSTED_PROXIES must contain the immediate reverse proxy');
    } finally {
        app()->instance('env', $originalEnvironment);
        config([
            'trustedproxy.required' => $originalRequired,
            'trustedproxy.proxies' => $originalProxies,
        ]);
    }
});

test('proxy-required mode accepts one explicit trusted proxy', function () {
    $originalEnvironment = app()->environment();
    $originalRequired = config('trustedproxy.required');
    $originalProxies = config('trustedproxy.proxies');

    app()->instance('env', 'local');
    config([
        'trustedproxy.required' => true,
        'trustedproxy.proxies' => ['172.30.255.1'],
    ]);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())->not->toThrow(LogicException::class);
    } finally {
        app()->instance('env', $originalEnvironment);
        config([
            'trustedproxy.required' => $originalRequired,
            'trustedproxy.proxies' => $originalProxies,
        ]);
    }
});

test('production rejects rate limiter stores that can silently fail open', function (string $store) {
    $originalEnvironment = app()->environment();
    $originalStore = config('cache.limiter');

    app()->instance('env', 'production');
    config(['cache.limiter' => $store]);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())
            ->toThrow(LogicException::class, 'RATE_LIMITER_STORE must use a persistent shared cache store in production.');
    } finally {
        app()->instance('env', $originalEnvironment);
        config(['cache.limiter' => $originalStore]);
    }
})->with(['array', 'null', 'failover', 'file', 'octane']);

test('production accepts a persistent shared rate limiter store', function () {
    $originalEnvironment = app()->environment();
    $originalStore = config('cache.limiter');

    app()->instance('env', 'production');
    config(['cache.limiter' => 'database']);

    try {
        expect(fn () => (new AppServiceProvider(app()))->boot())->not->toThrow(LogicException::class);
    } finally {
        app()->instance('env', $originalEnvironment);
        config(['cache.limiter' => $originalStore]);
    }
});
