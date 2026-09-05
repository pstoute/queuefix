<?php

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->processor = app(EmailProcessorService::class);
});

test('replaying one provider message creates only one ticket and message', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'imap:INBOX:123:456',
        'from_email' => 'customer@example.com',
        'subject' => 'A replayed request',
        'body_text' => 'Please help',
        'message_id' => '<replayed@example.com>',
    ];

    $first = $this->processor->processInboundEmail($email, $mailbox);
    $second = $this->processor->processInboundEmail($email, $mailbox);

    expect($second?->id)->toBe($first?->id)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(InboundEmailReceipt::count())->toBe(1);
});

test('replaying one provider message creates only one SLA timer', function () {
    SlaPolicy::factory()->create();
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'gmail:provider-sla-1',
        'from_email' => 'customer@example.com',
        'subject' => 'A replayed SLA request',
        'body_text' => 'Please help',
    ];

    $this->processor->processInboundEmail($email, $mailbox);
    $this->processor->processInboundEmail($email, $mailbox);

    expect(Ticket::count())->toBe(1)
        ->and(SlaTimer::count())->toBe(1);
});

test('replaying one provider reply appends it only once', function () {
    $mailbox = Mailbox::factory()->create();
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'mailbox_id' => $mailbox->id,
    ]);
    Message::factory()->create([
        'ticket_id' => $ticket->id,
        'message_id' => '<original@example.com>',
    ]);
    $email = [
        'provider_message_id' => 'imap:INBOX:123:789',
        'from_email' => $customer->email,
        'subject' => 'Re: Original',
        'body_text' => 'One reply',
        'message_id' => '<reply@example.com>',
        'in_reply_to' => '<original@example.com>',
    ];

    $first = $this->processor->processInboundEmail($email, $mailbox);
    $second = $this->processor->processInboundEmail($email, $mailbox);

    expect($first?->id)->toBe($ticket->id)
        ->and($second?->id)->toBe($ticket->id)
        ->and($ticket->messages()->count())->toBe(2);
});

test('distinct provider messages remain distinct even when RFC message IDs match', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'from_email' => 'customer@example.com',
        'subject' => 'Distinct provider messages',
        'body_text' => 'Same content',
        'message_id' => '<reused@example.com>',
    ];

    $first = $this->processor->processInboundEmail([
        ...$email,
        'provider_message_id' => 'imap:INBOX:123:1001',
    ], $mailbox);
    $second = $this->processor->processInboundEmail([
        ...$email,
        'provider_message_id' => 'imap:INBOX:123:1002',
    ], $mailbox);

    expect($second?->id)->not->toBe($first?->id)
        ->and(Ticket::count())->toBe(2)
        ->and(Message::count())->toBe(2);
});

test('distinct IMAP UIDVALIDITY values remain distinct', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'from_email' => 'customer@example.com',
        'subject' => 'UID reused after mailbox reset',
        'body_text' => 'Same UID, different UIDVALIDITY',
    ];

    $this->processor->processInboundEmail([
        ...$email,
        'provider_message_id' => 'imap:INBOX:123:1001',
    ], $mailbox);
    $this->processor->processInboundEmail([
        ...$email,
        'provider_message_id' => 'imap:INBOX:124:1001',
    ], $mailbox);

    expect(Ticket::count())->toBe(2)
        ->and(Message::count())->toBe(2);
});

test('the same provider identity remains isolated between mailboxes', function () {
    $firstMailbox = Mailbox::factory()->create();
    $secondMailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'imap:INBOX:123:1003',
        'from_email' => 'customer@example.com',
        'subject' => 'Delivered to two mailboxes',
        'body_text' => 'Same provider identity in separate accounts',
    ];

    $first = $this->processor->processInboundEmail($email, $firstMailbox);
    $second = $this->processor->processInboundEmail($email, $secondMailbox);

    expect($second?->id)->not->toBe($first?->id)
        ->and(Ticket::count())->toBe(2)
        ->and(Message::count())->toBe(2);
});

test('inbound processing rejects payloads without a provider identity before writes', function () {
    $mailbox = Mailbox::factory()->create();

    expect(fn () => $this->processor->processInboundEmail([
        'from_email' => 'customer@example.com',
        'subject' => 'Missing provider identity',
        'body_text' => 'Do not process this payload',
    ], $mailbox))->toThrow(\UnexpectedValueException::class);

    expect(Customer::count())->toBe(0)
        ->and(Ticket::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

test('provider identity does not depend on an RFC Message-ID header', function () {
    $mailbox = Mailbox::factory()->create();

    $ticket = $this->processor->processInboundEmail([
        'provider_message_id' => 'microsoft:immutable-123',
        'from_email' => 'customer@example.com',
        'subject' => 'No RFC identifier',
        'body_text' => 'This provider message is still identifiable',
    ], $mailbox);

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket?->messages()->first()?->message_id)->toBeNull();
});

test('replaying an attachment does not duplicate its row or stored object', function () {
    Storage::fake('local');
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'gmail:provider-attachment-1',
        'from_email' => 'customer@example.com',
        'subject' => 'A replayed attachment',
        'body_text' => 'See attached',
        'attachments' => [[
            'filename' => 'evidence.txt',
            'content' => 'stable contents',
            'mime_type' => 'text/plain',
        ]],
    ];

    $this->processor->processInboundEmail($email, $mailbox);
    $attachment = Attachment::sole();
    $this->processor->processInboundEmail($email, $mailbox);

    expect(Attachment::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([$attachment->path]);
    Storage::disk('local')->assertExists($attachment->path);
});

test('a failed transaction does not poison the provider identity claim', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'imap:INBOX:123:transaction-retry',
        'from_email' => 'customer@example.com',
        'subject' => 'Retry after failure',
        'body_text' => 'Process this exactly once after recovery',
    ];
    $failingTicketService = Mockery::mock(TicketService::class);
    $failingTicketService->shouldReceive('createTicket')
        ->once()
        ->andThrow(new RuntimeException('Injected ticket creation failure'));
    $failingProcessor = new EmailProcessorService($failingTicketService);

    expect(fn () => $failingProcessor->processInboundEmail($email, $mailbox))
        ->toThrow(RuntimeException::class, 'Injected ticket creation failure');

    expect(InboundEmailReceipt::count())->toBe(0)
        ->and(Customer::count())->toBe(0)
        ->and(Ticket::count())->toBe(0);

    $ticket = $this->processor->processInboundEmail($email, $mailbox);

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and(InboundEmailReceipt::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1);
});

test('rollback cleanup completes before a retry reuses the stable attachment path', function () {
    Storage::fake('local');
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'imap:INBOX:123:attachment-race',
        'from_email' => 'customer@example.com',
        'subject' => 'Attachment ownership race',
        'body_text' => 'Keep only the committed attachment',
        'attachments' => [[
            'filename' => 'evidence.txt',
            'content' => 'stable contents',
            'mime_type' => 'text/plain',
        ]],
    ];
    $failedPath = null;
    $failNextAttachment = true;

    Attachment::creating(function (Attachment $attachment) use (&$failedPath, &$failNextAttachment) {
        if (! $failNextAttachment) {
            return;
        }

        $failNextAttachment = false;
        $failedPath = $attachment->path;

        throw new RuntimeException('Injected failure after attachment storage');
    });

    expect(fn () => $this->processor->processInboundEmail($email, $mailbox))
        ->toThrow(RuntimeException::class, 'Injected failure after attachment storage');

    expect($failedPath)->not->toBeNull()
        ->and(Storage::disk('local')->exists($failedPath))->toBeFalse()
        ->and(InboundEmailReceipt::count())->toBe(0);

    $this->processor->processInboundEmail($email, $mailbox);
    $winningAttachment = Attachment::sole();

    Storage::disk('local')->assertExists($winningAttachment->path);
    expect($winningAttachment->path)->toBe($failedPath)
        ->and(InboundEmailReceipt::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(Attachment::count())->toBe(1);
});

test('retry adopts the same attachment path left by a hard crash', function () {
    Storage::fake('local');
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'imap:INBOX:123:abandoned-write';
    $idempotencyKey = hash('sha256', $mailbox->id."\0".$providerMessageId);
    $path = 'attachments/inbound/'.$idempotencyKey.'/'.hash('sha256', $idempotencyKey."\0".'0').'.txt';

    // Simulate a worker dying after storage succeeds but before its DB commit.
    Storage::disk('local')->put($path, 'abandoned partial contents');

    $this->processor->processInboundEmail([
        'provider_message_id' => $providerMessageId,
        'from_email' => 'customer@example.com',
        'subject' => 'Recover an abandoned write',
        'body_text' => 'Retry this provider message',
        'attachments' => [[
            'filename' => 'evidence.txt',
            'content' => 'committed contents',
            'mime_type' => 'text/plain',
        ]],
    ], $mailbox);

    $attachment = Attachment::sole();

    expect($attachment->path)->toBe($path)
        ->and(Storage::disk('local')->allFiles())->toBe([$path])
        ->and(Storage::disk('local')->get($path))->toBe('committed contents')
        ->and(InboundEmailReceipt::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(Attachment::count())->toBe(1);
});
