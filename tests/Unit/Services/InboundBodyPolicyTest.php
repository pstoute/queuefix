<?php

use App\Services\Email\InboundBodyPolicy;

test('body policy admits the exact aggregate byte limit', function () {
    config(['attachments.max_body_bytes' => 10]);

    expect((new InboundBodyPolicy)->normalize('12345', '67890'))
        ->toBe(['text' => '12345', 'html' => '67890']);
});

test('body policy terminally omits content over the aggregate byte limit', function () {
    config(['attachments.max_body_bytes' => 10]);

    expect((new InboundBodyPolicy)->normalize('123456', '78901'))
        ->toBe([
            'text' => InboundBodyPolicy::OMITTED_TEXT,
            'html' => null,
        ]);
});

test('body policy clamps configured limits to the shared storage width', function () {
    config(['attachments.max_body_bytes' => PHP_INT_MAX]);

    $policy = new InboundBodyPolicy;

    expect($policy->maxBytes())->toBe(InboundBodyPolicy::MAX_STORED_BODY_BYTES)
        ->and($policy->maxEncodedBytes(0))->toBe(22_369_620);
});
