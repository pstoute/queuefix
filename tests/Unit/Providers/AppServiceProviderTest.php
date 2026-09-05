<?php

use App\Providers\AppServiceProvider;

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
