<?php

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SlaService;
use App\Services\TicketService;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->slaService = Mockery::mock(SlaService::class);
    $this->ticketService = new TicketService($this->slaService);
});

test('creating a ticket generates ticket number', function () {
    $customer = Customer::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
        'priority' => TicketPriority::Normal,
    ], $customer);

    expect($ticket->ticket_number)->toStartWith('QF-');
    expect($ticket->subject)->toBe('Test ticket');
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::Normal);
    expect($ticket->customer_id)->toBe($customer->id);
});

test('ticket number is sequential', function () {
    $customer = Customer::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->times(3)->andReturnNull();

    $ticket1 = $this->ticketService->createTicket([
        'subject' => 'Ticket 1',
        'body' => 'Body 1',
    ], $customer);

    $ticket2 = $this->ticketService->createTicket([
        'subject' => 'Ticket 2',
        'body' => 'Body 2',
    ], $customer);

    $ticket3 = $this->ticketService->createTicket([
        'subject' => 'Ticket 3',
        'body' => 'Body 3',
    ], $customer);

    expect($ticket1->ticket_number)->toBe('QF-1');
    expect($ticket2->ticket_number)->toBe('QF-2');
    expect($ticket3->ticket_number)->toBe('QF-3');
});

test('creating ticket with mailbox assigns mailbox id', function () {
    $customer = Customer::factory()->create();
    $mailbox = Mailbox::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
    ], $customer, $mailbox->id);

    expect($ticket->mailbox_id)->toBe($mailbox->id);
});

test('creating ticket with assigned agent', function () {
    $customer = Customer::factory()->create();
    $agent = User::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
        'assigned_to' => $agent->id,
    ], $customer);

    expect($ticket->assigned_to)->toBe($agent->id);
});

test('adding a message updates last_activity_at', function () {
    $customer = Customer::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Initial message',
    ], $customer);

    $originalLastActivity = $ticket->last_activity_at;

    sleep(1);

    $message = $this->ticketService->addMessage($ticket, [
        'type' => MessageType::Reply,
        'body_text' => 'New reply',
        'body_html' => '<p>New reply</p>',
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
    ]);

    $ticket->refresh();
    expect($ticket->last_activity_at->timestamp)->toBeGreaterThan($originalLastActivity->timestamp);
});

test('adding agent reply records first response in SLA', function () {
    $customer = Customer::factory()->create();
    $agent = User::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Initial message',
    ], $customer);

    $slaTimer = \App\Models\SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_responded_at' => null,
    ]);
    $ticket->setRelation('slaTimer', $slaTimer);

    $this->slaService->shouldReceive('recordFirstResponse')
        ->once()
        ->with(Mockery::on(fn($t) => $t->id === $ticket->id));

    $this->ticketService->addMessage($ticket, [
        'type' => MessageType::Reply,
        'body_text' => 'Agent reply',
        'body_html' => '<p>Agent reply</p>',
        'sender_type' => User::class,
        'sender_id' => $agent->id,
    ]);
});

test('adding internal note does not record first response', function () {
    $customer = Customer::factory()->create();
    $agent = User::factory()->create();
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Initial message',
    ], $customer);

    $this->slaService->shouldNotReceive('recordFirstResponse');

    $this->ticketService->addMessage($ticket, [
        'type' => MessageType::InternalNote,
        'body_text' => 'Internal note',
        'body_html' => '<p>Internal note</p>',
        'sender_type' => User::class,
        'sender_id' => $agent->id,
    ]);
});

test('updating status calls SLA service', function () {
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
    $customer = Customer::factory()->create();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
    ], $customer);

    $this->slaService->shouldReceive('handleStatusChange')
        ->once()
        ->with(
            Mockery::on(fn($t) => $t->id === $ticket->id),
            TicketStatus::Open,
            TicketStatus::Pending
        );

    $this->slaService->shouldNotReceive('recordResolution');

    $this->ticketService->updateStatus($ticket, TicketStatus::Pending);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Pending);
});

test('updating status to resolved records resolution', function () {
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
    $customer = Customer::factory()->create();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
    ], $customer);

    $this->slaService->shouldReceive('handleStatusChange')->once();
    $this->slaService->shouldReceive('recordResolution')
        ->once()
        ->with(Mockery::on(fn($t) => $t->id === $ticket->id));

    $this->ticketService->updateStatus($ticket, TicketStatus::Resolved);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

test('updating status to closed records resolution', function () {
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
    $customer = Customer::factory()->create();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
    ], $customer);

    $this->slaService->shouldReceive('handleStatusChange')->once();
    $this->slaService->shouldReceive('recordResolution')
        ->once()
        ->with(Mockery::on(fn($t) => $t->id === $ticket->id));

    $this->ticketService->updateStatus($ticket, TicketStatus::Closed);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

test('assigning ticket updates assigned_to', function () {
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
    $customer = Customer::factory()->create();
    $agent = User::factory()->create();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
    ], $customer);

    $this->ticketService->assignTicket($ticket, $agent);

    $ticket->refresh();
    expect($ticket->assigned_to)->toBe($agent->id);
});

test('unassigning ticket sets assigned_to to null', function () {
    $this->slaService->shouldReceive('initializeTimer')->once()->andReturnNull();
    $customer = Customer::factory()->create();
    $agent = User::factory()->create();

    $ticket = $this->ticketService->createTicket([
        'subject' => 'Test ticket',
        'body' => 'Test body',
        'assigned_to' => $agent->id,
    ], $customer);

    $this->ticketService->assignTicket($ticket, null);

    $ticket->refresh();
    expect($ticket->assigned_to)->toBeNull();
});

test('merging tickets moves messages from secondary to primary', function () {
    $this->slaService->shouldReceive('initializeTimer')->twice()->andReturnNull();
    $customer = Customer::factory()->create();

    $primaryTicket = $this->ticketService->createTicket([
        'subject' => 'Primary ticket',
        'body' => 'Primary body',
    ], $customer);

    $secondaryTicket = $this->ticketService->createTicket([
        'subject' => 'Secondary ticket',
        'body' => 'Secondary body',
    ], $customer);

    $message1 = Message::factory()->create(['ticket_id' => $secondaryTicket->id]);
    $message2 = Message::factory()->create(['ticket_id' => $secondaryTicket->id]);

    $result = $this->ticketService->mergeTickets($primaryTicket, $secondaryTicket);

    expect($message1->fresh()->ticket_id)->toBe($primaryTicket->id);
    expect($message2->fresh()->ticket_id)->toBe($primaryTicket->id);
    expect($secondaryTicket->fresh()->status)->toBe(TicketStatus::Closed);
});

test('merging tickets syncs tags without duplicates', function () {
    $this->slaService->shouldReceive('initializeTimer')->twice()->andReturnNull();
    $customer = Customer::factory()->create();

    $primaryTicket = $this->ticketService->createTicket([
        'subject' => 'Primary ticket',
        'body' => 'Primary body',
    ], $customer);

    $secondaryTicket = $this->ticketService->createTicket([
        'subject' => 'Secondary ticket',
        'body' => 'Secondary body',
    ], $customer);

    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();
    $tag3 = Tag::factory()->create();

    $primaryTicket->tags()->attach([$tag1->id, $tag2->id]);
    $secondaryTicket->tags()->attach([$tag2->id, $tag3->id]);

    $result = $this->ticketService->mergeTickets($primaryTicket, $secondaryTicket);

    $primaryTicket->refresh();
    expect($primaryTicket->tags)->toHaveCount(3);
    expect($primaryTicket->tags->pluck('id')->toArray())->toContain($tag1->id, $tag2->id, $tag3->id);
});

test('get next ticket number returns counter plus one', function () {
    Setting::set('ticket_counter', '10', 'system');

    $nextNumber = $this->ticketService->getNextTicketNumber();

    expect($nextNumber)->toBe('QF-11');
});

test('get next ticket number returns QF-1 when counter is zero', function () {
    $nextNumber = $this->ticketService->getNextTicketNumber();

    expect($nextNumber)->toBe('QF-1');
});

test('get next ticket number uses configured prefix', function () {
    Setting::set('ticket_prefix', 'TK', 'general');
    Setting::set('ticket_counter', '5', 'system');

    $nextNumber = $this->ticketService->getNextTicketNumber();

    expect($nextNumber)->toBe('TK-6');
});
