<?php

use App\Services\EmailAddressNormalizer;

test('email normalizer parses names lowercases addresses and deduplicates', function () {
    $addresses = app(EmailAddressNormalizer::class)->normalize(
        '"Jane, Jr." <JANE@Example.com>, jane@example.com, Support <support@example.com>',
    );

    expect($addresses)->toBe([
        ['email' => 'jane@example.com', 'display_name' => 'Jane, Jr.'],
        ['email' => 'support@example.com', 'display_name' => 'Support'],
    ]);
});

test('email normalizer accepts connector payloads and rejects malformed or injectable addresses', function () {
    $addresses = app(EmailAddressNormalizer::class)->normalize([
        ['address' => 'VALID@Example.com', 'name' => 'Valid Person'],
        ['email' => 'invalid'],
        "safe@example.com\r\nBcc: attacker@example.com",
        null,
    ]);

    expect($addresses)->toBe([
        ['email' => 'valid@example.com', 'display_name' => 'Valid Person'],
    ]);
});
