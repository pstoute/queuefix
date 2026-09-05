<?php

use App\Exceptions\InboundEmailRejected;
use App\Services\Email\InboundEmailNormalizer;

function normalizableInboundEmail(array $overrides = []): array
{
    return [
        'provider_message_id' => 'gmail:normalizer-test',
        'provider_remote_id' => 'normalizer-test',
        'from_email' => 'Customer@Example.com',
        'from_name' => 'Customer',
        'to_email' => 'support@example.com',
        'subject' => 'Question',
        'body_text' => 'Please help',
        'attachments' => [],
        ...$overrides,
    ];
}

test('normalizer admits exact byte limits and canonicalizes provider metadata', function () {
    $messageId = '<'.str_repeat('m', 241).'@example.com>';
    $normalized = app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail([
        'from_name' => str_repeat('n', 255),
        'subject' => str_repeat('s', 255),
        'message_id' => $messageId,
        'references' => ['<first@example.com>', '<first@example.com>', '<second@example.com>'],
    ]));

    expect($normalized['from_email'])->toBe('customer@example.com')
        ->and(strlen($normalized['from_name']))->toBe(255)
        ->and(strlen($normalized['subject']))->toBe(255)
        ->and(strlen($normalized['message_id']))->toBe(255)
        ->and($normalized['references'])->toBe(['<first@example.com>', '<second@example.com>']);
});

test('normalizer rejects metadata one byte over the storage boundary', function (array $overrides, string $reason) {
    try {
        app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail($overrides));
        test()->fail('Expected inbound metadata rejection.');
    } catch (InboundEmailRejected $exception) {
        expect($exception->reasonCode)->toBe($reason);
    }
})->with([
    'name' => [['from_name' => str_repeat('n', 256)], 'invalid_from_name'],
    'subject' => [['subject' => str_repeat('s', 256)], 'invalid_subject'],
    'multibyte subject' => [['subject' => str_repeat('é', 128)], 'invalid_subject'],
    'message id' => [['message_id' => str_repeat('m', 256)], 'invalid_message_id'],
]);

test('normalizer rejects malformed scalar and control-bearing fields', function (array $overrides, string $reason) {
    try {
        app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail($overrides));
        test()->fail('Expected inbound metadata rejection.');
    } catch (InboundEmailRejected $exception) {
        expect($exception->reasonCode)->toBe($reason);
    }
})->with([
    'missing sender' => [['from_email' => null], 'invalid_from_email'],
    'non-scalar sender' => [['from_email' => ['attacker@example.com']], 'invalid_from_email'],
    'invalid sender syntax' => [['from_email' => 'not-an-address'], 'invalid_from_email'],
    'NUL sender' => [['from_email' => "attacker@example.com\0suffix"], 'invalid_from_email'],
    'newline subject' => [['subject' => "Hello\r\nInjected"], 'invalid_subject'],
    'invalid UTF-8 name' => [['from_name' => "\xC3\x28"], 'invalid_from_name'],
    'NUL body' => [['body_text' => "hello\0world"], 'invalid_body'],
]);

test('normalizer derives text for html-only messages', function () {
    $normalized = app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail([
        'body_text' => null,
        'body_html' => '<p>Hello <strong>there</strong></p>',
    ]));

    expect($normalized['body_text'])->toBe('Hello there')
        ->and($normalized['body_html'])->toBe('<p>Hello <strong>there</strong></p>');
});

test('normalizer accepts the exact reference count and rejects one over', function () {
    $exact = array_map(
        fn (int $index): string => "<reference-{$index}@example.com>",
        range(1, InboundEmailNormalizer::MAX_REFERENCE_COUNT),
    );
    $normalized = app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail([
        'references' => $exact,
    ]));

    expect($normalized['references'])->toHaveCount(InboundEmailNormalizer::MAX_REFERENCE_COUNT);

    try {
        app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail([
            'references' => [...$exact, '<one-over@example.com>'],
        ]));
        test()->fail('Expected inbound References rejection.');
    } catch (InboundEmailRejected $exception) {
        expect($exception->reasonCode)->toBe('invalid_references');
    }
});

test('normalizer rejects malformed reference members before query construction', function (mixed $references) {
    expect(fn () => app(InboundEmailNormalizer::class)->normalize(normalizableInboundEmail([
        'references' => $references,
    ])))->toThrow(InboundEmailRejected::class);
})->with([
    'non-string member' => [[['unexpected']]],
    'oversized member' => [[str_repeat('r', 256)]],
    'control-bearing string' => ["<first@example.com>\r\n<second@example.com>"],
]);
