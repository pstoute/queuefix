<?php

use App\Models\Mailbox;
use App\Services\Email\GmailConnector;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\Resource\UsersMessages;

function captureGmailRawMessage(array $data): array
{
    $capturedRaw = null;
    $messages = Mockery::mock(UsersMessages::class);
    $messages->shouldReceive('send')
        ->once()
        ->with('me', Mockery::on(function (GmailMessage $message) use (&$capturedRaw) {
            $encoded = strtr((string) $message->getRaw(), '-_', '+/');
            $capturedRaw = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4));

            return true;
        }))
        ->andReturn(new GmailMessage);

    $service = Mockery::mock(Gmail::class);
    $service->users_messages = $messages;
    $mailbox = Mailbox::factory()->make(['email' => 'support@example.com']);
    $connector = new GmailConnector;
    $reflection = new ReflectionClass($connector);
    $reflection->getProperty('service')->setValue($connector, $service);
    $reflection->getProperty('mailbox')->setValue($connector, $mailbox);

    return [$connector->sendEmail($data), $capturedRaw];
}

test('Gmail serialization prevents values from injecting new headers', function () {
    [$sent, $raw] = captureGmailRawMessage([
        'to' => 'customer@example.com',
        'subject' => "Account update\r\nBcc: injected-subject@example.com",
        'text' => 'Legitimate body',
        'headers' => [
            'In-Reply-To' => "<original@example.com>\r\nBcc: injected-reply@example.com",
            'References' => "<first@example.com>\r\nX-Evil: injected-reference",
            'X-Arbitrary' => 'must not cross the connector boundary',
        ],
    ]);

    expect($sent)->toBeTrue()
        ->and($raw)->toBeString()
        ->and(preg_match_all('/^Bcc:/mi', $raw))->toBe(0)
        ->and(preg_match_all('/^X-Evil:/mi', $raw))->toBe(0)
        ->and(preg_match_all('/^X-Arbitrary:/mi', $raw))->toBe(0)
        ->and($raw)->toContain('Legitimate body');
});

test('Gmail serialization preserves legitimate threaded multipart email', function () {
    [$sent, $raw] = captureGmailRawMessage([
        'to' => 'customer@example.com',
        'subject' => '[QF-123] A legitimate reply',
        'text' => 'Plain reply',
        'html' => '<p>HTML reply</p>',
        'headers' => [
            'Subject' => '[QF-123] A legitimate reply',
            'In-Reply-To' => '<original@example.com>',
            'References' => '<first@example.com> <original@example.com>',
        ],
    ]);

    expect($sent)->toBeTrue()
        ->and($raw)->toContain('From: support@example.com')
        ->and($raw)->toContain('To: customer@example.com')
        ->and($raw)->toContain('Subject: [QF-123] A legitimate reply')
        ->and(preg_match_all('/^Subject:/mi', $raw))->toBe(1)
        ->and($raw)->toContain('In-Reply-To: <original@example.com>')
        ->and($raw)->toContain('References: <first@example.com> <original@example.com>')
        ->and($raw)->toContain('Content-Type: multipart/alternative;')
        ->and($raw)->toContain('Plain reply')
        ->and($raw)->toContain('<p>HTML reply</p>');
});

test('Gmail serialization safely encodes a Unicode subject', function () {
    [$sent, $raw] = captureGmailRawMessage([
        'to' => 'customer@example.com',
        'subject' => '[QF-123] Résumé update',
        'text' => 'Unicode subject control',
        'headers' => [],
    ]);

    expect($sent)->toBeTrue()
        ->and(preg_match_all('/^Subject:/mi', $raw))->toBe(1)
        ->and($raw)->toContain('=?utf-8?Q?R=C3=A9sum=C3=A9?=');
});

test('Gmail rejects address control characters before calling the API', function (string $mailboxEmail, string $recipient) {
    $messages = Mockery::mock(UsersMessages::class);
    $messages->shouldNotReceive('send');
    $service = Mockery::mock(Gmail::class);
    $service->users_messages = $messages;
    $mailbox = Mailbox::factory()->make(['email' => $mailboxEmail]);
    $connector = new GmailConnector;
    $reflection = new ReflectionClass($connector);
    $reflection->getProperty('service')->setValue($connector, $service);
    $reflection->getProperty('mailbox')->setValue($connector, $mailbox);

    expect($connector->sendEmail([
        'to' => $recipient,
        'subject' => 'Address validation',
        'text' => 'This must not be sent',
        'headers' => [],
    ]))->toBeFalse();
})->with([
    'recipient injection' => ['support@example.com', "customer@example.com\r\nBcc: injected@example.com"],
    'sender injection' => ["support@example.com\r\nBcc: injected@example.com", 'customer@example.com'],
]);
