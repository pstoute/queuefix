<?php

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MicrosoftGraphConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('Graph connects with credentials hydrated by a real mailbox model', function () {
    Http::fake();
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'access_token' => 'persisted-access-token',
            'refresh_token' => 'persisted-refresh-token',
            'token_expires_at' => (string) now()->addHour()->timestamp,
        ],
    ]);

    $connector = new MicrosoftGraphConnector;

    expect($connector->connect($mailbox->fresh()))->toBeTrue();
    Http::assertNothingSent();
});

test('Graph persists refreshed credentials through a real mailbox model', function () {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);
    $mailbox = Mailbox::factory()->create([
        'credentials' => [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'persisted-refresh-token',
            'token_expires_at' => (string) now()->subMinute()->timestamp,
            'provider_account_id' => 'account-123',
        ],
    ]);

    $connector = new MicrosoftGraphConnector;
    $credentialUpdates = 0;
    DB::listen(function ($query) use (&$credentialUpdates): void {
        if (preg_match('/^update\s+[`"]?mailboxes[`"]?/i', $query->sql) === 1) {
            $credentialUpdates++;
        }
    });

    expect($connector->connect($mailbox->fresh()))->toBeTrue();

    $hydratedMailbox = $mailbox->fresh();

    expect($hydratedMailbox->getDecryptedCredential('access_token'))->toBe('rotated-access-token')
        ->and($hydratedMailbox->getDecryptedCredential('refresh_token'))->toBe('rotated-refresh-token')
        ->and((int) $hydratedMailbox->getDecryptedCredential('token_expires_at'))->toBeGreaterThan(now()->timestamp)
        ->and($hydratedMailbox->getDecryptedCredential('provider_account_id'))->toBe('account-123')
        ->and($credentialUpdates)->toBe(1);
});

test('Graph sends secure reply addresses through the provider-native field', function () {
    Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response([], 202)]);
    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();

    expect($connector->sendEmail([
        'to' => 'customer@example.com',
        'subject' => '[QF-123] Reply',
        'text' => 'Reply body',
        'reply_to' => 'support+0123456789abcdef0123456789abcdef0123456789abcdef@example.com',
        'headers' => ['In-Reply-To' => '<original@example.com>'],
    ]))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/me/sendMail')
        && data_get($request->data(), 'message.replyTo.0.emailAddress.address') === 'support+0123456789abcdef0123456789abcdef0123456789abcdef@example.com'
        && data_get($request->data(), 'message.toRecipients.0.emailAddress.address') === 'customer@example.com');
});

test('Graph uses immutable provider IDs and acknowledges only after fetching', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/me/messages?')) {
            return Http::response(['value' => [['id' => 'immutable/id+123=']]]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/me/messages/immutable%2Fid%2B123%3D?')) {
            return Http::response([
                'id' => 'immutable/id+123=',
                'subject' => 'Provider identity',
                'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer']],
                'toRecipients' => [['emailAddress' => ['address' => 'support@example.com']]],
                'body' => ['contentType' => 'text', 'content' => 'Please help'],
                'receivedDateTime' => '2026-09-05T12:00:00Z',
                'internetMessageHeaders' => [],
                'hasAttachments' => false,
                'internetMessageId' => null,
            ]);
        }

        return Http::response([], 200);
    });

    $mailbox = Mockery::mock(Mailbox::class)->makePartial();
    $mailbox->id = '00000000-0000-0000-0000-000000000001';
    $mailbox->shouldReceive('getDecryptedCredential')->with('access_token')->andReturn('access-token');
    $mailbox->shouldReceive('getDecryptedCredential')->with('refresh_token')->andReturn(null);
    $mailbox->shouldReceive('getDecryptedCredential')->with('token_expires_at')->andReturn((string) now()->addHour()->timestamp);

    $connector = new MicrosoftGraphConnector;

    expect($connector->connect($mailbox))->toBeTrue();
    $references = iterator_to_array($connector->fetchNewEmailReferences(now()));
    $messages = [$connector->fetchEmail($references[0])];

    expect($references)->toHaveCount(1)
        ->and($references[0])->toHaveKeys(['provider_message_id', 'provider_remote_id'])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]['provider_message_id'])->toBe('microsoft:immutable/id+123=')
        ->and($messages[0]['provider_remote_id'])->toBe('immutable/id+123=');

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && ($query['$filter'] ?? null) === 'isRead eq false'
            && ($query['$select'] ?? null) === 'id'
            && $request->hasHeader('Prefer', 'IdType="ImmutableId"');
    });
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), '/me/messages/immutable%2Fid%2B123%3D?')
            && ($query['$select'] ?? null) === 'body';
    });

    expect($connector->acknowledge($messages[0]))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/me/messages/immutable%2Fid%2B123%3D')
        && $request->hasHeader('Prefer', 'IdType="ImmutableId"')
        && $request->data() === ['isRead' => true]);
});

test('Graph omits a body one byte over the configured limit', function () {
    config(['attachments.max_body_bytes' => 10]);
    $fixture = graphMessageFixture('provider-oversized-body', false);
    $fixture['body'] = ['contentType' => 'text', 'content' => '12345678901'];

    $message = invokeGraphMessageParsing(new MicrosoftGraphConnector, $fixture);

    expect($message['body_text'])->toContain('omitted')
        ->and($message['body_html'])->toBeNull();
});

test('Graph admits text and HTML exactly at the configured limit', function (string $contentType) {
    config(['attachments.max_body_bytes' => 10]);
    $fixture = graphMessageFixture("provider-exact-{$contentType}", false);
    $fixture['body'] = ['contentType' => $contentType, 'content' => '1234567890'];

    $message = invokeGraphMessageParsing(new MicrosoftGraphConnector, $fixture);

    expect($message[$contentType === 'html' ? 'body_html' : 'body_text'])->toBe('1234567890');

    if ($contentType === 'html') {
        expect($message['body_text'])->toBe('');
    }
})->with(['text', 'html']);

test('Graph exact-limit HTML appends to an existing ticket without retrying', function () {
    config(['attachments.max_body_bytes' => 10]);
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $ticket = Ticket::factory()->create([
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
    ]);
    Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<original@example.com>',
    ]);
    $fixture = graphMessageFixture('provider-html-reply', false);
    $mailbox->update(['reply_address_template' => 'support+{token}@example.com']);
    $fixture['toRecipients'] = [[
        'emailAddress' => [
            'address' => app(\App\Services\Email\TicketReplyCapabilityService::class)->replyAddress($ticket),
        ],
    ]];
    $fixture['body'] = ['contentType' => 'html', 'content' => '<b>123</b>'];
    $fixture['internetMessageHeaders'] = [[
        'name' => 'In-Reply-To',
        'value' => '<original@example.com>',
    ]];
    $message = invokeGraphMessageParsing(new MicrosoftGraphConnector, $fixture);

    $result = app(EmailProcessorService::class)->processInboundEmail($message, $mailbox);
    $reply = Message::query()
        ->where('ticket_id', $ticket->id)
        ->where('body_html', '<b>123</b>')
        ->sole();

    expect($result?->is($ticket))->toBeTrue()
        ->and($ticket->messages()->count())->toBe(2)
        ->and($reply->body_text)->toBe('')
        ->and($reply->body_html)->toBe('<b>123</b>');
});

test('Graph streams a body response only to a bounded envelope', function () {
    config(['attachments.max_body_bytes' => 10]);
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (($query['$select'] ?? null) === 'body') {
            return Http::response(json_encode([
                'body' => ['contentType' => 'text', 'content' => str_repeat('x', 100_000)],
            ], JSON_THROW_ON_ERROR));
        }

        return Http::response(graphMessageFixture('provider-stream-limit', false));
    });

    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();
    $message = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-stream-limit',
        'provider_remote_id' => 'provider-stream-limit',
    ]);

    expect($message['body_text'])->toContain('omitted')
        ->and($message['body_html'])->toBeNull();
});

test('Graph rejects attachment metadata over the count limit before content fetch', function () {
    config(['attachments.max_files_per_message' => 10]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/messages/provider-over-count?')) {
            return Http::response(graphMessageFixture('provider-over-count', true));
        }

        if (str_contains($request->url(), '/me/messages/provider-over-count/attachments?')) {
            return Http::response([
                'value' => array_map(fn (int $index): array => [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'id' => "attachment-{$index}",
                    'name' => "attachment-{$index}.txt",
                    'contentType' => 'text/plain',
                    'size' => 1,
                ], range(1, 11)),
            ]);
        }

        return Http::response('content must not be fetched', 500);
    });

    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();
    $message = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-over-count',
        'provider_remote_id' => 'provider-over-count',
    ]);

    expect($message['attachments'])->toBe([])
        ->and($message['attachment_rejection']['reason_code'])->toBe('too_many_files')
        ->and($message['attachment_rejection']['reported_count'])->toBe(11);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/$value'));
});

test('Graph fetches one admitted attachment as raw bounded content', function () {
    $content = "\x89PNG\r\n\x1a\nordinary";
    Http::fake(function (Request $request) use ($content) {
        if (str_contains($request->url(), '/me/messages/provider-control?')) {
            return Http::response(graphMessageFixture('provider-control', true));
        }

        if (str_contains($request->url(), '/me/messages/provider-control/attachments?')) {
            return Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => 'attachment-control',
                'name' => 'evidence.png',
                'contentType' => 'image/png',
                'size' => strlen($content),
            ]]]);
        }

        if (str_ends_with($request->url(), '/attachments/attachment-control/$value')) {
            return Http::response($content, 200, ['Content-Type' => 'application/octet-stream']);
        }

        return Http::response([], 404);
    });

    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();
    $message = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-control',
        'provider_remote_id' => 'provider-control',
    ]);

    expect($message)->not->toHaveKey('attachment_rejection')
        ->and($message['attachments'])->toHaveCount(1)
        ->and($message['attachments'][0]['content'])->toBe($content);
});

test('Graph rejects missing attachment size metadata before content fetch', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/messages/provider-missing-size?')) {
            return Http::response(graphMessageFixture('provider-missing-size', true));
        }

        if (str_contains($request->url(), '/me/messages/provider-missing-size/attachments?')) {
            return Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => 'attachment-missing-size',
                'name' => 'evidence.txt',
                'contentType' => 'text/plain',
            ]]]);
        }

        return Http::response('content must not be fetched', 500);
    });

    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();
    $message = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-missing-size',
        'provider_remote_id' => 'provider-missing-size',
    ]);

    expect($message['attachments'])->toBe([])
        ->and($message['attachment_rejection']['reason_code'])->toBe('size_unavailable');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/$value'));
});

test('Graph caps an underreported attachment response at the actual byte limit', function () {
    config([
        'attachments.max_file_bytes' => 5,
        'attachments.max_message_bytes' => 10,
    ]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/messages/provider-underreported?')) {
            return Http::response(graphMessageFixture('provider-underreported', true));
        }

        if (str_contains($request->url(), '/me/messages/provider-underreported/attachments?')) {
            return Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => 'attachment-underreported',
                'name' => 'evidence.txt',
                'contentType' => 'text/plain',
                'size' => 1,
            ]]]);
        }

        if (str_ends_with($request->url(), '/attachments/attachment-underreported/$value')) {
            return Http::response(str_repeat('x', 1000));
        }

        return Http::response([], 404);
    });

    $connector = new MicrosoftGraphConnector;
    expect($connector->connect(graphMailbox()))->toBeTrue();
    $message = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-underreported',
        'provider_remote_id' => 'provider-underreported',
    ]);

    expect($message['attachments'])->toBe([])
        ->and($message['attachment_rejection']['reason_code'])->toBe('file_too_large');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/$value'));
});

test('Graph enforces mailbox storage quota before attachment content fetch', function () {
    config([
        'attachments.max_installation_bytes' => 100,
        'attachments.max_mailbox_bytes' => 5,
    ]);
    $mailboxRecord = Mailbox::factory()->create();
    $ticket = Ticket::factory()->create(['mailbox_id' => $mailboxRecord->id]);
    $message = Message::factory()->create(['ticket_id' => $ticket->id]);
    Attachment::factory()->create([
        'message_id' => $message->id,
        'path' => 'existing/mailbox-file',
        'size' => 4,
    ]);
    expect((int) \Illuminate\Support\Facades\DB::table('attachments')
        ->join('messages', 'messages.id', '=', 'attachments.message_id')
        ->join('tickets', 'tickets.id', '=', 'messages.ticket_id')
        ->where('tickets.mailbox_id', $mailboxRecord->id)
        ->sum('attachments.size'))->toBe(4);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/me/messages/provider-quota?')) {
            return Http::response(graphMessageFixture('provider-quota', true));
        }

        if (str_contains($request->url(), '/me/messages/provider-quota/attachments?')) {
            return Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => 'attachment-quota',
                'name' => 'evidence.txt',
                'contentType' => 'text/plain',
                'size' => 2,
            ]]]);
        }

        return Http::response('content must not be fetched', 500);
    });

    $connector = new MicrosoftGraphConnector;
    $reflection = new ReflectionClass($connector);
    $reflection->getProperty('mailbox')->setValue($connector, $mailboxRecord);
    $reflection->getProperty('accessToken')->setValue($connector, 'access-token');
    $email = $connector->fetchEmail([
        'provider_message_id' => 'microsoft:provider-quota',
        'provider_remote_id' => 'provider-quota',
    ]);

    expect($email['attachment_rejection']['reason_code'])->toBe('mailbox_quota_exceeded');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/$value'));
});

function graphMailbox(string $id = '00000000-0000-0000-0000-000000000001'): Mailbox
{
    $mailbox = Mockery::mock(Mailbox::class)->makePartial();
    $mailbox->setKeyType('string');
    $mailbox->setIncrementing(false);
    $mailbox->setRawAttributes(['id' => $id]);
    $mailbox->shouldReceive('getDecryptedCredential')->with('access_token')->andReturn('access-token');
    $mailbox->shouldReceive('getDecryptedCredential')->with('refresh_token')->andReturn(null);
    $mailbox->shouldReceive('getDecryptedCredential')->with('token_expires_at')->andReturn((string) now()->addHour()->timestamp);

    return $mailbox;
}

/** @return array<string, mixed> */
function graphMessageFixture(string $id, bool $hasAttachments): array
{
    return [
        'id' => $id,
        'subject' => 'Attachment policy',
        'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer']],
        'toRecipients' => [['emailAddress' => ['address' => 'support@example.com']]],
        'body' => ['contentType' => 'text', 'content' => 'Please review'],
        'receivedDateTime' => '2026-09-05T12:00:00Z',
        'internetMessageHeaders' => [],
        'hasAttachments' => $hasAttachments,
        'internetMessageId' => null,
    ];
}

/** @param array<string, mixed> $message */
function invokeGraphMessageParsing(MicrosoftGraphConnector $connector, array $message): array
{
    return (new ReflectionClass($connector))->getMethod('parseMessage')->invoke($connector, $message);
}
