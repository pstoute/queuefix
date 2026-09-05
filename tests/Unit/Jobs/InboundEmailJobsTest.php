<?php

use App\Enums\AttachmentScanStatus;
use App\Jobs\FetchEmailsJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\Attachment;
use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\InboundEmailConnector;
use App\Services\Email\MailboxConnectorFactory;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
});

test('fetching dispatches processing without acknowledging the provider message', function () {
    Queue::fake();
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'imap:INBOX:123:456',
        'provider_remote_id' => '456',
        'uid_validity' => 123,
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->withArgs(fn (Mailbox $argument) => $argument->is($mailbox))->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->once()->andReturn([$providerReference]);
    $connector->shouldNotReceive('acknowledge');
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new FetchEmailsJob($mailbox->id))->handle($connectorFactory);

    Queue::assertPushed(ProcessInboundEmailJob::class, function (ProcessInboundEmailJob $job): bool {
        $serialized = serialize($job);

        return str_contains($serialized, 'imap:INBOX:123:456')
            && ! str_contains($serialized, 'Do not acknowledge this yet');
    });
    expect(Ticket::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

test('processing jobs reject hydrated message or attachment data at the queue boundary', function () {
    $mailboxId = '00000000-0000-4000-8000-000000000001';

    expect(fn () => new ProcessInboundEmailJob([
        'provider_message_id' => 'gmail:provider-1',
        'provider_remote_id' => 'provider-1',
        'attachments' => [['content' => "\x89PNG"]],
    ], $mailboxId))->toThrow(InvalidArgumentException::class, 'provider references only')
        ->and(fn () => new ProcessInboundEmailJob([
            'provider_message_id' => 'gmail:provider-1',
            'provider_remote_id' => ['provider-1'],
        ], $mailboxId))->toThrow(InvalidArgumentException::class, 'scalar values only')
        ->and(fn () => new ProcessInboundEmailJob([
            'provider_message_id' => 'gmail:provider-1',
            'provider_remote_id' => str_repeat('x', 2049),
        ], $mailboxId))->toThrow(InvalidArgumentException::class, 'bounded stable provider identities')
        ->and(fn () => new ProcessInboundEmailJob([
            'provider_message_id' => 'gmail:provider-1',
            'provider_remote_id' => 'provider-1',
        ], str_repeat('x', 1_000_000)))->toThrow(InvalidArgumentException::class, 'valid mailbox UUID');

    $job = new ProcessInboundEmailJob([
        'provider_message_id' => 'gmail:provider-1',
        'provider_remote_id' => 'provider-1',
    ], $mailboxId);

    expect(fn () => json_encode(['job' => serialize($job)], JSON_THROW_ON_ERROR))->not->toThrow(Throwable::class);
});

test('processor failure leaves the provider message unacknowledged', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'gmail:provider-failure-1',
        'provider_remote_id' => 'provider-failure-1',
        'from_email' => 'customer@example.com',
        'subject' => 'Processing fails',
        'body_text' => 'Keep this unread',
    ];
    $providerReference = [
        'provider_message_id' => $email['provider_message_id'],
        'provider_remote_id' => $email['provider_remote_id'],
    ];
    $processor = Mockery::mock(EmailProcessorService::class);
    $processor->shouldReceive('processInboundEmail')
        ->once()
        ->andThrow(new RuntimeException('Injected processing failure'));
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldNotReceive('acknowledge');
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    expect(fn () => (new ProcessInboundEmailJob($providerReference, $mailbox->id))
        ->handle($processor, $connectorFactory))
        ->toThrow(RuntimeException::class, 'Injected processing failure');

    expect(InboundEmailReceipt::count())->toBe(0)
        ->and(Ticket::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

test('provider hydration failure leaves the provider message unacknowledged for retry', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'imap:INBOX:123:456',
        'provider_remote_id' => '456',
        'uid_validity' => 123,
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)
        ->andThrow(new RuntimeException('IMAP message part fetch failed.'));
    $connector->shouldNotReceive('acknowledge');
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    expect(fn () => (new ProcessInboundEmailJob($providerReference, $mailbox->id))
        ->handle(app(EmailProcessorService::class), $connectorFactory))
        ->toThrow(RuntimeException::class, 'IMAP message part fetch failed.');

    expect(InboundEmailReceipt::count())->toBe(0)
        ->and(Ticket::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

test('acknowledgement failure retries without duplicating committed processing', function () {
    $mailbox = Mailbox::factory()->create();
    $email = [
        'provider_message_id' => 'microsoft:immutable-retry-1',
        'provider_remote_id' => 'immutable-retry-1',
        'from_email' => 'customer@example.com',
        'subject' => 'Acknowledgement retry',
        'body_text' => 'Persist once and retry acknowledgement',
    ];
    $providerReference = [
        'provider_message_id' => $email['provider_message_id'],
        'provider_remote_id' => $email['provider_remote_id'],
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->twice()->withArgs(fn (Mailbox $argument) => $argument->is($mailbox))->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnFalse();
    $connector->shouldReceive('acknowledge')->once()->with($providerReference)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->twice()->andReturn($connector);
    $processor = app(EmailProcessorService::class);
    $job = new ProcessInboundEmailJob($providerReference, $mailbox->id);

    expect(fn () => $job->handle($processor, $connectorFactory))
        ->toThrow(RuntimeException::class, 'Unable to acknowledge the processed provider message.');

    expect(InboundEmailReceipt::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1);

    $job->handle($processor, $connectorFactory);

    expect(InboundEmailReceipt::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1);
});

test('provider attachment rejection is committed and acknowledged without poison retries', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:provider-over-limit',
        'provider_remote_id' => 'provider-over-limit',
    ];
    $email = [
        ...$providerReference,
        'from_email' => 'attacker@example.com',
        'to_email' => $mailbox->email,
        'subject' => 'Oversized attachment',
        'body_text' => 'The body remains available to support staff.',
        'attachments' => [],
        'attachment_rejection' => [
            'reason_code' => 'file_too_large',
            'reported_count' => 1,
            'reported_bytes' => 30 * 1024 * 1024,
        ],
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new ProcessInboundEmailJob($providerReference, $mailbox->id))
        ->handle(app(EmailProcessorService::class), $connectorFactory);

    $attachment = Attachment::query()->sole();
    expect(InboundEmailReceipt::query()->count())->toBe(1)
        ->and(Ticket::query()->count())->toBe(1)
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Rejected)
        ->and($attachment->path)->toBeNull()
        ->and($attachment->getRawOriginal('rejection_reason'))->toBe('file_too_large');
});
