<?php

use App\Support\CanonicalUrlConfiguration;

test('canonical URL configuration normalizes safe application origins', function (string $url, string $root, string $scheme, string $host) {
    $configuration = CanonicalUrlConfiguration::from($url);

    expect($configuration->root)->toBe($root)
        ->and($configuration->scheme)->toBe($scheme)
        ->and($configuration->host)->toBe($host);
})->with([
    ['http://localhost:8000', 'http://localhost:8000', 'http', 'localhost'],
    ['HTTPS://Support.Example.Test:443/helpdesk/', 'https://support.example.test/helpdesk', 'https', 'support.example.test'],
    ['https://support.example.test:8443', 'https://support.example.test:8443', 'https', 'support.example.test'],
    ['http://127.0.0.1:80', 'http://127.0.0.1', 'http', '127.0.0.1'],
    ['http://[::1]:8000', 'http://[::1]:8000', 'http', '[::1]'],
]);

test('canonical URL configuration rejects unsafe or ambiguous application URLs', function (?string $url) {
    expect(fn () => CanonicalUrlConfiguration::from($url))
        ->toThrow(InvalidArgumentException::class, 'APP_URL must be an absolute HTTP(S) URL');
})->with([
    null,
    '',
    'localhost:8000',
    'ftp://support.example.test',
    'https://user:secret@support.example.test',
    'https://support.example.test?next=https://attacker.invalid',
    'https://support.example.test#attacker',
]);
