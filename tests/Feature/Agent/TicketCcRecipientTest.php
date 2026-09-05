<?php

use App\Enums\MailboxType;
use App\Enums\MessageType;
use App\Jobs\SendEmailReplyJob;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\Ticket;
use App\Models\TicketCcAudit;
use App\Models\TicketCcRecipient;
use App\Models\User;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\ImapConnector;
use App\Services\TicketCcService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('staff public replies normalize deduplicate persist and audit the outbound recipient set', function () {
    Queue::fake();

    $actor = User::factory()->create();
    $customer = Customer::factory()->create(['email' => 'primary@example.com']);
    $mailbox = Mailbox::factory()->create(['email' => 'support@example.com']);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'mailbox_id' => $mailbox->id,
    ]);

    actingAs($actor);
    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Public reply',
        'type' => MessageType::Reply->value,
        'cc' => ['Other@Example.com', 'other@example.com', 'primary@example.com', 'support@example.com'],
    ])->assertRedirect();

    $message = Message::query()->where('ticket_id', $ticket->id)->sole();
    $recipient = TicketCcRecipient::query()->sole();

    $messageRecipient = $message->ccRecipients()->sole();
    expect($recipient->email)->toBe('other@example.com')
        ->and($recipient->source)->toBe('staff_reply')
        ->and($recipient->validation_state)->toBe('approved')
        ->and($recipient->added_by_type)->toBe(User::class)
        ->and($recipient->added_by_id)->toBe($actor->id)
        ->and($recipient->approved_at)->not->toBeNull()
        ->and($messageRecipient->email)->toBe('other@example.com')
        ->and($messageRecipient->source)->toBe('staff_reply')
        ->and($messageRecipient->validation_state)->toBe('approved')
        ->and($messageRecipient->created_by_type)->toBe(User::class)
        ->and($messageRecipient->created_by_id)->toBe($actor->id);

    $setAudit = TicketCcAudit::query()->where('event', 'outbound_recipient_set')->sole();
    expect($setAudit->metadata['to'])->toBe('primary@example.com')
        ->and($setAudit->metadata['cc'])->toBe(['other@example.com']);
    $this->assertDatabaseHas('ticket_cc_audits', [
        'ticket_id' => $ticket->id,
        'ticket_cc_recipient_id' => $recipient->id,
        'event' => 'recipient_added',
        'email' => 'other@example.com',
    ]);
    Queue::assertPushed(SendEmailReplyJob::class);

    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.cc_recipients', 1)
            ->where('ticket.cc_recipients.0.email', 'other@example.com')
            ->has('ticket.messages.0.cc_recipients', 1)
            ->where('ticket.messages.0.cc_recipients.0.email', 'other@example.com')
        );
});

test('invalid addresses are rejected and internal notes cannot carry customer visible recipients', function () {
    Queue::fake();

    $actor = User::factory()->create();
    $ticket = Ticket::factory()->create();
    actingAs($actor);

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Invalid outbound address',
        'type' => MessageType::Reply->value,
        'cc' => ['not-an-email'],
    ])->assertSessionHasErrors('cc.0');

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Private note',
        'type' => MessageType::InternalNote->value,
        'cc' => ['external@example.com'],
    ])->assertSessionHasErrors('cc');

    expect($ticket->messages()->count())->toBe(0);
    $this->assertDatabaseCount('ticket_cc_recipients', 0);
    $this->assertDatabaseCount('message_cc_recipients', 0);
});

test('inbound cc headers merge normalized recipients with names and exclude primary addresses', function () {
    $mailbox = Mailbox::factory()->create(['email' => 'support@example.com']);
    $processor = app(EmailProcessorService::class);

    $ticket = $processor->processInboundEmail([
        'from_email' => 'customer@example.com',
        'from_name' => 'Customer',
        'to_email' => 'support@example.com',
        'subject' => 'CC import',
        'body_text' => 'Please include my colleague.',
        'message_id' => '<inbound@example.com>',
        'cc' => 'Colleague <COLLEAGUE@example.com>, colleague@example.com, invalid, customer@example.com, support@example.com',
    ], $mailbox);

    $recipient = TicketCcRecipient::query()->sole();
    $message = $ticket->messages()->sole();

    expect($recipient->email)->toBe('colleague@example.com')
        ->and($recipient->display_name)->toBe('Colleague')
        ->and($recipient->source)->toBe('inbound_header')
        ->and($message->ccRecipients()->pluck('email')->all())->toBe(['colleague@example.com']);
    $this->assertDatabaseHas('ticket_cc_audits', [
        'message_id' => $message->id,
        'event' => 'inbound_recipient_set',
    ]);
});

test('unrelated inbound senders cannot add recipients and approved cc senders cannot expand the list', function () {
    $mailbox = Mailbox::factory()->create(['email' => 'support@example.com']);
    $customer = Customer::factory()->create(['email' => 'owner@example.com']);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'mailbox_id' => $mailbox->id,
        'ticket_number' => 'QF-765',
    ]);
    $approved = TicketCcRecipient::create([
        'ticket_id' => $ticket->id,
        'email' => 'approved@example.com',
        'source' => 'staff_reply',
        'validation_state' => 'approved',
        'approved_at' => now(),
    ]);
    $processor = app(EmailProcessorService::class);

    $processor->processInboundEmail([
        'from_email' => 'attacker@example.com',
        'to_email' => 'support@example.com',
        'subject' => 'Re: [QF-765] Existing ticket',
        'body_text' => 'Unrelated sender',
        'cc' => ['new@example.com'],
    ], $mailbox);

    $processor->processInboundEmail([
        'from_email' => $approved->email,
        'to_email' => 'support@example.com',
        'subject' => 'Re: [QF-765] Existing ticket',
        'body_text' => 'Approved participant',
        'cc' => [$approved->email, 'new@example.com'],
    ], $mailbox);

    expect(TicketCcRecipient::query()->pluck('email')->all())->toBe(['approved@example.com']);
    $this->assertDatabaseMissing('ticket_cc_recipients', ['email' => 'new@example.com']);
});

test('customer portal replies can select only existing approved recipients on their own ticket', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $otherTicket = Ticket::factory()->create();
    $approved = TicketCcRecipient::create([
        'ticket_id' => $ticket->id,
        'email' => 'approved@example.com',
        'source' => 'inbound_header',
        'validation_state' => 'approved',
        'approved_at' => now(),
    ]);
    $unrelated = TicketCcRecipient::create([
        'ticket_id' => $otherTicket->id,
        'email' => 'unrelated@example.com',
        'source' => 'inbound_header',
        'validation_state' => 'approved',
        'approved_at' => now(),
    ]);

    actingAs($customer, 'customer');
    post(route('customer.tickets.reply', $ticket), [
        'body' => 'Attempt unrelated recipient',
        'cc_recipient_ids' => [$unrelated->id],
    ])->assertSessionHasErrors('cc_recipient_ids');
    expect($ticket->messages()->count())->toBe(0);

    post(route('customer.tickets.reply', $ticket), [
        'body' => 'Approved participant included',
        'cc_recipient_ids' => [$approved->id],
    ])->assertRedirect();

    $message = $ticket->messages()->sole();
    expect($message->ccRecipients()->pluck('email')->all())->toBe(['approved@example.com']);
    $this->assertDatabaseHas('ticket_cc_audits', [
        'message_id' => $message->id,
        'event' => 'customer_recipient_set',
    ]);
});

test('removing a ticket recipient is authorized and auditable without rewriting message snapshots', function () {
    $actor = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $message = Message::factory()->fromAgent()->create([
        'ticket_id' => $ticket->id,
        'sender_id' => $actor->id,
    ]);
    $recipient = app(TicketCcService::class)->recordStaffReply(
        $ticket,
        $message,
        ['remove@example.com'],
        $actor,
    )->sole();

    actingAs($actor);
    delete(route('agent.tickets.cc-recipients.destroy', [$ticket, $recipient]))->assertRedirect();

    expect($recipient->fresh()->removed_at)->not->toBeNull()
        ->and($message->ccRecipients()->pluck('email')->all())->toBe(['remove@example.com']);
    $this->assertDatabaseHas('ticket_cc_audits', [
        'ticket_cc_recipient_id' => $recipient->id,
        'event' => 'recipient_removed',
        'email' => 'remove@example.com',
    ]);
});

test('send job delivers the immutable cc snapshot and preserves threading headers', function () {
    Bus::fake();

    $actor = User::factory()->create();
    $customer = Customer::factory()->create(['email' => 'primary@example.com']);
    $mailbox = Mailbox::factory()->create([
        'email' => 'support@example.com',
        'type' => MailboxType::Imap,
    ]);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'mailbox_id' => $mailbox->id,
        'ticket_number' => 'QF-900',
    ]);
    Message::factory()->create([
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
        'message_id' => '<customer-message@example.com>',
    ]);
    $message = Message::factory()->fromAgent()->create([
        'ticket_id' => $ticket->id,
        'sender_id' => $actor->id,
        'body_text' => 'Outbound body',
        'body_html' => '<p>Outbound body</p>',
    ]);
    app(TicketCcService::class)->recordStaffReply(
        $ticket,
        $message,
        ['primary@example.com', 'cc@example.com', 'CC@example.com'],
        $actor,
    );

    $connector = Mockery::mock(ImapConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('sendEmail')->once()->with(Mockery::on(
        fn (array $data): bool => $data['to'] === 'primary@example.com'
            && $data['cc'] === ['cc@example.com']
            && $data['headers']['In-Reply-To'] === '<customer-message@example.com>'
            && str_contains($data['headers']['References'], '<customer-message@example.com>'),
    ))->andReturnTrue();
    app()->instance(ImapConnector::class, $connector);

    (new SendEmailReplyJob($ticket->id, $message->id))->handle(
        app(EmailProcessorService::class),
        app(TicketCcService::class),
    );

    expect($message->ccRecipients()->sole()->fresh()->delivered_at)->not->toBeNull();
    $this->assertDatabaseHas('ticket_cc_audits', [
        'message_id' => $message->id,
        'event' => 'outbound_delivered',
    ]);
});

test('send job refuses internal notes even when recipient rows are present', function () {
    $actor = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['type' => MailboxType::Imap]);
    $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
    $message = Message::factory()->internalNote()->create([
        'ticket_id' => $ticket->id,
        'sender_id' => $actor->id,
    ]);
    $recipient = MessageCcRecipient::create([
        'ticket_id' => $ticket->id,
        'message_id' => $message->id,
        'email' => 'should-not-send@example.com',
        'source' => 'invalid_fixture',
        'validation_state' => 'approved',
    ]);
    $connector = Mockery::mock(ImapConnector::class);
    $connector->shouldNotReceive('connect');
    app()->instance(ImapConnector::class, $connector);

    (new SendEmailReplyJob($ticket->id, $message->id))->handle(
        app(EmailProcessorService::class),
        app(TicketCcService::class),
    );

    expect($recipient->fresh()->delivered_at)->toBeNull();
    $this->assertDatabaseCount('ticket_cc_audits', 0);
});
