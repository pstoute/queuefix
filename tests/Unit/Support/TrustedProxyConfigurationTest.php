<?php

use App\Support\TrustedProxyConfiguration;

test('trusted proxy configuration accepts explicit addresses and narrow cidrs', function () {
    expect(TrustedProxyConfiguration::parse('127.0.0.1, 192.0.2.0/28, 2001:db8::/48'))
        ->toBe(['127.0.0.1', '192.0.2.0/28', '2001:db8::/48']);
});

test('trusted proxy configuration rejects trust-all values', function (string $proxy) {
    expect(fn () => TrustedProxyConfiguration::parse($proxy))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '*',
    '**',
    'REMOTE_ADDR',
    '0.0.0.0/0',
    '0.0.0.0/00',
    '0.0.0.0/+0',
    '0.0.0.0/ 0',
    '::/0',
    'not-an-ip',
    '192.0.2.1/33',
    '2001:db8::/129',
]);
