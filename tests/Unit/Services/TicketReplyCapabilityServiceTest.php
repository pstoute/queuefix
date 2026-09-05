<?php

use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketReplyCapability;
use App\Services\Email\TicketReplyCapabilityService;
use Illuminate\Support\Facades\DB;

test('reply capabilities are stable, encrypted at rest, and hidden from serialization', function () {
    $mailbox = Mailbox::factory()->create([
        'reply_address_template' => 'support+{token}@example.com',
    ]);
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $ticket = Ticket::factory()->create([
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
    ]);
    $service = app(TicketReplyCapabilityService::class);

    $firstAddress = $service->replyAddress($ticket);
    $secondAddress = $service->replyAddress($ticket);
    preg_match('/\Asupport\+([a-f0-9]{48})@example\.com\z/', (string) $firstAddress, $matches);
    $token = $matches[1] ?? '';
    $capability = TicketReplyCapability::query()->sole();
    $storedToken = DB::table('ticket_reply_capabilities')->value('token');

    expect($firstAddress)->toBe($secondAddress)
        ->and($token)->toHaveLength(48)
        ->and($storedToken)->toBeString()->not->toContain($token)
        ->and($capability->token)->toBe($token)
        ->and($capability->toArray())->not->toHaveKeys(['token', 'token_hash'])
        ->and($service->resolveInboundTicketForUpdate($mailbox, $firstAddress, $customer)?->is($ticket))->toBeTrue()
        ->and(TicketReplyCapability::query()->count())->toBe(1);
});

test('reply capability resolution requires both the receiving mailbox and customer address', function () {
    $mailbox = Mailbox::factory()->create([
        'reply_address_template' => 'reply-{token}@example.com',
    ]);
    $otherMailbox = Mailbox::factory()->create([
        'reply_address_template' => 'reply-{token}@example.com',
    ]);
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $ticket = Ticket::factory()->create([
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
    ]);
    $service = app(TicketReplyCapabilityService::class);
    $address = $service->replyAddress($ticket);

    $forwardee = Customer::factory()->create(['email' => 'forwardee@example.com']);

    expect($service->resolveInboundTicketForUpdate($mailbox, $address, $forwardee))->toBeNull()
        ->and($service->resolveInboundTicketForUpdate($otherMailbox, $address, $customer))->toBeNull()
        ->and($service->resolveInboundTicketForUpdate($mailbox, 'reply-'.str_repeat('0', 48).'@example.com', $customer))->toBeNull()
        ->and($service->resolveInboundTicketForUpdate($mailbox, 'reply-truncated@example.com', $customer))->toBeNull();
});

test('revoked capabilities fail closed and are rotated before later outbound mail', function () {
    $mailbox = Mailbox::factory()->create([
        'reply_address_template' => 'reply-{token}@example.com',
    ]);
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
    ]);
    $service = app(TicketReplyCapabilityService::class);
    $oldAddress = $service->replyAddress($ticket);
    $service->revokeForTicket($ticket);

    expect($service->resolveInboundTicketForUpdate($mailbox, $oldAddress, $customer))->toBeNull();

    $newAddress = $service->replyAddress($ticket);

    expect($newAddress)->not->toBe($oldAddress)
        ->and($service->resolveInboundTicketForUpdate($mailbox, $oldAddress, $customer))->toBeNull()
        ->and($service->resolveInboundTicketForUpdate($mailbox, $newAddress, $customer)?->is($ticket))->toBeTrue();
});

test('mailboxes without a valid routable template do not issue capabilities', function (mixed $template) {
    $mailbox = Mailbox::factory()->create(['reply_address_template' => $template]);
    $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
    $service = app(TicketReplyCapabilityService::class);

    expect($service->templateIsValid($template))->toBeFalse()
        ->and($service->replyAddress($ticket))->toBeNull()
        ->and(TicketReplyCapability::query()->count())->toBe(0);
})->with([
    'missing' => null,
    'empty' => '',
    'no placeholder' => 'reply@example.com',
    'multiple placeholders' => '{token}+{token}@example.com',
    'invalid address' => 'not-an-address-{token}',
    'local part too long' => str_repeat('a', 17).'{token}@example.com',
]);

test('a valid template contains one token and fits email address limits', function () {
    $service = app(TicketReplyCapabilityService::class);

    expect($service->templateIsValid('support+{token}@example.com'))->toBeTrue()
        ->and($service->templateIsValid('replies@{token}.example.com'))->toBeTrue();
});
