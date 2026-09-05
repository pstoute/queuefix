<?php

use App\Http\Middleware\AddStrictTransportSecurity;
use Illuminate\Http\Middleware\TrustProxies;

test('the trusted deployment proxy enables hsts for forwarded https', function () {
    TrustProxies::at(['172.30.255.1']);

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.255.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get(route('version.show', absolute: false))
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', AddStrictTransportSecurity::POLICY);
    } finally {
        TrustProxies::at(config('trustedproxy.proxies'));
    }
});

test('an untrusted peer cannot inject hsts with a forwarded proto header', function () {
    TrustProxies::at(['172.30.255.1']);

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get(route('version.show', absolute: false))
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    } finally {
        TrustProxies::at(config('trustedproxy.proxies'));
    }
});
