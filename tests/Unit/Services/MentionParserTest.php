<?php

use App\Services\MentionParser;

test('mention parser normalizes case and removes duplicate handles', function () {
    $handles = app(MentionParser::class)->handles('@Alex please pair with @alex and (@BOB_smith).');

    expect($handles)->toBe(['alex', 'bob_smith']);
});

test('mention parser ignores email addresses and malformed or oversized handles', function () {
    $oversized = str_repeat('a', 49);
    $handles = app(MentionParser::class)->handles(
        "Send test@example.com to @valid-handle, not @-broken or @{$oversized}.",
    );

    expect($handles)->toBe(['valid-handle']);
});
