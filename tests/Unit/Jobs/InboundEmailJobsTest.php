<?php

use App\Jobs\FetchEmailsJob;
use App\Jobs\ProcessInboundEmailJob;
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
    $email = [
        'provider_message_id' => 'imap:INBOX:123:456',
        'provider_remote_id' => '456',
        'from_email' => 'customer@example.com',
        'subject' => 'Queued for processing',
        'body_text' => 'Do not acknowledge this yet',
    ];
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->once()->withArgs(fn (Mailbox $argument) => $argument->is($mailbox))->andReturnTrue();
    $connector->shouldReceive('fetchNewEmails')->once()->andReturn([$email]);
    $connector->shouldNotReceive('acknowledge');
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->once()->andReturn($connector);

    (new FetchEmailsJob($mailbox->id))->handle($connectorFactory);

    Queue::assertPushed(ProcessInboundEmailJob::class, 1);
    expect(Ticket::count())->toBe(0)
        ->and(Message::count())->toBe(0);
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
    $processor = Mockery::mock(EmailProcessorService::class);
    $processor->shouldReceive('processInboundEmail')
        ->once()
        ->andThrow(new RuntimeException('Injected processing failure'));
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldNotReceive('make');

    expect(fn () => (new ProcessInboundEmailJob($email, $mailbox->id))
        ->handle($processor, $connectorFactory))
        ->toThrow(RuntimeException::class, 'Injected processing failure');

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
    $connector = Mockery::mock(InboundEmailConnector::class);
    $connector->shouldReceive('connect')->twice()->withArgs(fn (Mailbox $argument) => $argument->is($mailbox))->andReturnTrue();
    $connector->shouldReceive('acknowledge')->twice()->with($email)->andReturn(false, true);
    $connectorFactory = Mockery::mock(MailboxConnectorFactory::class);
    $connectorFactory->shouldReceive('make')->twice()->andReturn($connector);
    $processor = app(EmailProcessorService::class);
    $job = new ProcessInboundEmailJob($email, $mailbox->id);

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
