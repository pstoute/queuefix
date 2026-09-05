<?php

use App\Enums\AttachmentScanStatus;
use App\Jobs\FetchEmailsJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Jobs\SendEmailReplyJob;
use App\Models\Attachment;
use App\Models\InboundEmailClaim;
use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReplyCapability;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\InboundEmailClaimService;
use App\Services\Email\InboundEmailConnector;
use App\Services\Email\InboundEmailPollingPolicy;
use App\Services\Email\MailboxConnectorFactory;
use App\Services\Email\TicketReplyCapabilityService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

test('mailbox fetch jobs expose a bounded uniqueness contract', function () {
    $mailboxId = '00000000-0000-4000-8000-000000000001';
    $job = new FetchEmailsJob($mailboxId);

    expect($job->uniqueId())->toBe($mailboxId)
        ->and($job->uniqueFor)->toBe(300);
});

test('processing jobs finish before queue retry and claim lease boundaries', function () {
    config([
        'queue.connections.database.retry_after' => 90,
        'inbound.claim_lease_seconds' => 120,
    ]);
    $job = new ProcessInboundEmailJob([
        'provider_message_id' => 'gmail:runtime-boundary',
        'provider_remote_id' => 'runtime-boundary',
    ], '00000000-0000-4000-8000-000000000001');

    expect($job->timeout)->toBe(60)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and(config('queue.connections.database.retry_after'))->toBeLessThan(
            app(InboundEmailPollingPolicy::class)->claimLeaseSeconds(),
        );
});

test('repeated polls dispatch one pending job per provider identity', function () {
    Queue::fake();
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'imap:INBOX:123:duplicate',
        'provider_remote_id' => '456',
        'uid_validity' => 123,
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->twice()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->twice()->andReturn([$providerReference]);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->twice()->andReturn($connector);
    $job = new FetchEmailsJob($mailbox->id);

    $job->handle($connectorFactory);
    $job->handle($connectorFactory);

    Queue::assertPushed(ProcessInboundEmailJob::class, 1);
});

test('one provider poll dispatches no more than the configured batch size', function () {
    Queue::fake();
    config(['inbound.poll_batch_size' => 3]);
    $mailbox = Mailbox::factory()->create();
    $providerReferences = array_map(fn (int $index): array => [
        'provider_message_id' => "gmail:backlog-{$index}",
        'provider_remote_id' => "backlog-{$index}",
    ], range(1, 100));
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->once()->andReturn($providerReferences);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new FetchEmailsJob($mailbox->id))->handle($connectorFactory);

    Queue::assertPushed(ProcessInboundEmailJob::class, 3);
});

test('a bounded scan skips active claims and still fills the dispatch batch', function () {
    Queue::fake();
    config(['inbound.poll_batch_size' => 2]);
    $mailbox = Mailbox::factory()->create();
    $providerReferences = array_map(fn (int $index): array => [
        'provider_message_id' => "gmail:scan-{$index}",
        'provider_remote_id' => "scan-{$index}",
    ], range(1, 5));
    $claimService = app(InboundEmailClaimService::class);
    foreach (array_slice($providerReferences, 0, 3) as $reference) {
        expect($claimService->acquire(
            $mailbox->id,
            $reference['provider_message_id'],
            (string) Str::uuid(),
        ))->toBeTrue();
    }
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->once()->andReturn($providerReferences);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new FetchEmailsJob($mailbox->id))->handle($connectorFactory);

    Queue::assertPushed(ProcessInboundEmailJob::class, 2);
    expect(InboundEmailClaim::query()->count())->toBe(5);
});

test('an uncertain dispatch failure retains its lease until recovery is safe', function () {
    config(['inbound.claim_lease_seconds' => 120]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:dispatch-failure',
        'provider_remote_id' => 'dispatch-failure',
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->once()->andReturn([$providerReference]);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));

    (new FetchEmailsJob($mailbox->id))->handle(
        $connectorFactory,
        app(InboundEmailClaimService::class),
        app(InboundEmailPollingPolicy::class),
        $dispatcher,
    );

    $claimService = app(InboundEmailClaimService::class);
    expect(InboundEmailClaim::query()->count())->toBe(1)
        ->and($claimService->acquire(
            $mailbox->id,
            $providerReference['provider_message_id'],
            (string) Str::uuid(),
        ))->toBeFalse();

    $this->travel(121)->seconds();
    expect($claimService->acquire(
        $mailbox->id,
        $providerReference['provider_message_id'],
        (string) Str::uuid(),
    ))->toBeTrue();
});

test('a synchronous dispatch failure cannot erase a terminal claim', function () {
    config(['inbound.max_failure_count' => 1]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:sync-final-failure',
        'provider_remote_id' => 'sync-final-failure',
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmailReferences')->once()->andReturn([$providerReference]);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ProcessInboundEmailJob $job): never {
        $job->failed(new RuntimeException('Synchronous final failure'));

        throw new RuntimeException('Synchronous final failure');
    });

    (new FetchEmailsJob($mailbox->id))->handle(
        $connectorFactory,
        app(InboundEmailClaimService::class),
        app(InboundEmailPollingPolicy::class),
        $dispatcher,
    );

    $claim = InboundEmailClaim::query()->sole();
    expect($claim->failure_count)->toBe(1)
        ->and($claim->exhausted_at)->not->toBeNull();
});

test('a stale processing token exits before provider work', function () {
    config(['inbound.claim_lease_seconds' => 120]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:stale-token',
        'provider_remote_id' => 'stale-token',
    ];
    $claimService = app(InboundEmailClaimService::class);
    $oldToken = (string) Str::uuid();
    $newToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $oldToken))->toBeTrue();
    $this->travel(121)->seconds();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $newToken))->toBeTrue();

    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldNotReceive('make');

    (new ProcessInboundEmailJob($providerReference, $mailbox->id, $oldToken))
        ->handle(app(EmailProcessorService::class), $connectorFactory, null, $claimService);

    expect(InboundEmailClaim::query()->sole()->claim_token)->toBe($newToken);
});

test('a lease reclaimed during provider hydration fences processing and acknowledgement', function () {
    config(['inbound.claim_lease_seconds' => 120]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:hydration-fence',
        'provider_remote_id' => 'hydration-fence',
    ];
    $claimService = app(InboundEmailClaimService::class);
    $oldToken = (string) Str::uuid();
    $newToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $oldToken))->toBeTrue();

    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->andReturnUsing(function () use (
        $claimService,
        $mailbox,
        $providerReference,
        $newToken,
    ): array {
        $this->travel(121)->seconds();
        expect($claimService->acquire(
            $mailbox->id,
            $providerReference['provider_message_id'],
            $newToken,
        ))->toBeTrue();

        return [
            ...$providerReference,
            'from_email' => 'customer@example.com',
            'subject' => 'Fence stale hydration',
            'body_text' => 'Do not process this stale delivery.',
        ];
    });
    $connector->shouldNotReceive('acknowledge');
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);
    $processor = Mockery::mock(EmailProcessorService::class);
    $processor->shouldNotReceive('processInboundEmail');

    (new ProcessInboundEmailJob($providerReference, $mailbox->id, $oldToken))
        ->handle($processor, $connectorFactory, null, $claimService);

    expect(InboundEmailClaim::query()->sole()->claim_token)->toBe($newToken)
        ->and(InboundEmailReceipt::query()->count())->toBe(0)
        ->and(Ticket::query()->count())->toBe(0);
});

test('the final queue failure hook defers reclaiming the owned lease', function () {
    config([
        'inbound.claim_lease_seconds' => 120,
        'inbound.retry_base_seconds' => 60,
    ]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:failed-hook',
        'provider_remote_id' => 'failed-hook',
    ];
    $claimService = app(InboundEmailClaimService::class);
    $claimToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $claimToken))->toBeTrue();

    (new ProcessInboundEmailJob($providerReference, $mailbox->id, $claimToken))
        ->failed(new RuntimeException('Final attempt failed'));

    $claim = InboundEmailClaim::query()->sole();
    expect($claim->failure_count)->toBe(1)
        ->and($claim->retry_not_before?->isFuture())->toBeTrue()
        ->and($claimService->acquire(
            $mailbox->id,
            $providerReference['provider_message_id'],
            (string) Str::uuid(),
        ))->toBeFalse();
});

test('a claimed job releases its lease only after acknowledgement', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:claimed-success',
        'provider_remote_id' => 'claimed-success',
    ];
    $email = [
        ...$providerReference,
        'from_email' => 'customer@example.com',
        'subject' => 'Claimed success',
        'body_text' => 'Process this once.',
    ];
    $claimService = app(InboundEmailClaimService::class);
    $claimToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $claimToken))->toBeTrue();
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new ProcessInboundEmailJob($providerReference, $mailbox->id, $claimToken))
        ->handle(app(EmailProcessorService::class), $connectorFactory, null, $claimService);

    expect(InboundEmailClaim::query()->count())->toBe(0)
        ->and(InboundEmailReceipt::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(1);
});

test('a claimed terminal rejection is acknowledged and releases its lease', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:claimed-rejection',
        'provider_remote_id' => 'claimed-rejection',
    ];
    $email = [
        ...$providerReference,
        'from_email' => "attacker@example.com\0invalid",
        'subject' => 'Malformed metadata',
        'body_text' => 'Reject this once.',
    ];
    $claimService = app(InboundEmailClaimService::class);
    $claimToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $claimToken))->toBeTrue();
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new ProcessInboundEmailJob($providerReference, $mailbox->id, $claimToken))
        ->handle(app(EmailProcessorService::class), $connectorFactory, null, $claimService);

    expect(InboundEmailClaim::query()->count())->toBe(0)
        ->and(InboundEmailReceipt::query()->sole()->disposition)->toBe('rejected')
        ->and(Ticket::query()->count())->toBe(0);
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
    $claimService = app(InboundEmailClaimService::class);
    $claimToken = (string) Str::uuid();
    expect($claimService->acquire($mailbox->id, $providerReference['provider_message_id'], $claimToken))->toBeTrue();
    $job = new ProcessInboundEmailJob($providerReference, $mailbox->id, $claimToken);

    expect(fn () => $job->handle($processor, $connectorFactory, null, $claimService))
        ->toThrow(RuntimeException::class, 'Unable to acknowledge the processed provider message.');

    expect(InboundEmailReceipt::count())->toBe(1)
        ->and(InboundEmailClaim::count())->toBe(1)
        ->and(Ticket::count())->toBe(1)
        ->and(Message::count())->toBe(1);

    $job->handle($processor, $connectorFactory, null, $claimService);

    expect(InboundEmailReceipt::count())->toBe(1)
        ->and(InboundEmailClaim::count())->toBe(0)
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

test('a safely omitted provider body is committed and acknowledged once', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:provider-oversized-body',
        'provider_remote_id' => 'provider-oversized-body',
    ];
    $email = [
        ...$providerReference,
        'from_email' => 'attacker@example.com',
        'to_email' => $mailbox->email,
        'subject' => 'Oversized body',
        'body_text' => '[Inbound message body omitted because it exceeded the configured safety limit.]',
        'body_html' => null,
        'attachments' => [],
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new ProcessInboundEmailJob($providerReference, $mailbox->id))
        ->handle(app(EmailProcessorService::class), $connectorFactory);

    expect(InboundEmailReceipt::query()->count())->toBe(1)
        ->and(Message::query()->sole()->body_text)->toContain('omitted');
});

test('processing enforces the body policy when a connector violates its contract', function () {
    config(['attachments.max_body_bytes' => 10]);
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'microsoft:provider-unbounded-body',
        'provider_remote_id' => 'provider-unbounded-body',
    ];
    $email = [
        ...$providerReference,
        'from_email' => 'attacker@example.com',
        'to_email' => $mailbox->email,
        'subject' => 'Connector contract violation',
        'body_text' => '12345678901',
        'attachments' => [],
    ];
    $acknowledgement = [
        ...$email,
        'body_text' => '[Inbound message body omitted because it exceeded the configured safety limit.]',
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($acknowledgement)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new ProcessInboundEmailJob($providerReference, $mailbox->id))
        ->handle(app(EmailProcessorService::class), $connectorFactory);

    expect(Message::query()->sole()->body_text)->toContain('omitted')
        ->and(InboundEmailReceipt::query()->count())->toBe(1);
});

test('terminally malformed provider metadata records one rejection and is acknowledged', function () {
    $mailbox = Mailbox::factory()->create();
    $providerReference = [
        'provider_message_id' => 'gmail:malformed-metadata',
        'provider_remote_id' => 'malformed-metadata',
    ];
    $email = [
        ...$providerReference,
        'from_email' => "attacker@example.com\0invalid",
        'subject' => 'Malformed metadata',
        'body_text' => 'This must not reach persistence.',
        'attachments' => [],
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->twice()->andReturnTrue();
    $connector->shouldReceive('fetchEmail')->once()->with($providerReference)->andReturn($email);
    $connector->shouldReceive('acknowledge')->once()->with($email)->andReturnTrue();
    $connector->shouldReceive('acknowledge')->once()->with($providerReference)->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->twice()->andReturn($connector);
    $job = new ProcessInboundEmailJob($providerReference, $mailbox->id);

    $job->handle(app(EmailProcessorService::class), $connectorFactory);
    $job->handle(app(EmailProcessorService::class), $connectorFactory);

    $receipt = InboundEmailReceipt::query()->sole();
    expect($receipt->ticket_id)->toBeNull()
        ->and($receipt->disposition)->toBe('rejected')
        ->and($receipt->rejection_reason)->toBe('invalid_from_email')
        ->and(Ticket::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

test('outbound replies carry a stable secure Reply-To capability', function () {
    $mailbox = Mailbox::factory()->create([
        'reply_address_template' => 'support+{token}@example.com',
    ]);
    $ticket = Ticket::factory()->for($mailbox)->create();
    $message = Message::factory()->for($ticket)->fromAgent()->create();
    $processor = Mockery::mock(EmailProcessorService::class);
    $processor->shouldReceive('buildOutboundHeaders')->once()->andReturn([
        'Subject' => 'Re: Secure thread',
    ]);
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->withArgs(fn (Mailbox $argument) => $argument->is($mailbox))->andReturnTrue();
    $connector->shouldReceive('sendEmail')->once()->withArgs(function (array $data): bool {
        expect($data['reply_to'])->toMatch('/^support\+[0-9a-f]{48}@example\.com$/');

        return true;
    })->andReturnTrue();
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new SendEmailReplyJob($ticket->id, $message->id))->handle(
        $processor,
        app(TicketReplyCapabilityService::class),
        $connectorFactory,
    );

    expect(TicketReplyCapability::query()->count())->toBe(1);
});
