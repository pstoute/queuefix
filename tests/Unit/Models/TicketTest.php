<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Setting;
use App\Models\SlaTimer;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
});

test('auto-generation of ticket_number in boot method', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->ticket_number)->not->toBeNull();
    expect($ticket->ticket_number)->toStartWith('QF-');
});

test('ticket number is sequential', function () {
    $ticket1 = Ticket::factory()->create();
    $ticket2 = Ticket::factory()->create();
    $ticket3 = Ticket::factory()->create();

    expect($ticket1->ticket_number)->toBe('QF-1');
    expect($ticket2->ticket_number)->toBe('QF-2');
    expect($ticket3->ticket_number)->toBe('QF-3');
});

test('ticket can have custom ticket number', function () {
    $ticket = Ticket::factory()->create(['ticket_number' => 'CUSTOM-123']);

    expect($ticket->ticket_number)->toBe('CUSTOM-123');
});

test('ticket automatically sets last_activity_at on creation', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->last_activity_at)->not->toBeNull();
    expect($ticket->last_activity_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('ticket belongs to customer', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    expect($ticket->customer)->toBeInstanceOf(Customer::class);
    expect($ticket->customer->id)->toBe($customer->id);
});

test('ticket can have assignee', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $user->id]);

    expect($ticket->assignee)->toBeInstanceOf(User::class);
    expect($ticket->assignee->id)->toBe($user->id);
});

test('ticket can be unassigned', function () {
    $ticket = Ticket::factory()->create(['assigned_to' => null]);

    expect($ticket->assignee)->toBeNull();
});

test('ticket belongs to mailbox', function () {
    $mailbox = Mailbox::factory()->create();
    $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);

    expect($ticket->mailbox)->toBeInstanceOf(Mailbox::class);
    expect($ticket->mailbox->id)->toBe($mailbox->id);
});

test('ticket can have messages', function () {
    $ticket = Ticket::factory()->create();
    Message::factory()->count(3)->create(['ticket_id' => $ticket->id]);

    expect($ticket->messages)->toHaveCount(3);
    expect($ticket->messages->first())->toBeInstanceOf(Message::class);
});

test('ticket can have SLA timer', function () {
    $ticket = Ticket::factory()->create();
    $slaTimer = SlaTimer::factory()->create(['ticket_id' => $ticket->id]);

    expect($ticket->slaTimer)->toBeInstanceOf(SlaTimer::class);
    expect($ticket->slaTimer->id)->toBe($slaTimer->id);
});

test('ticket can have tags', function () {
    $ticket = Ticket::factory()->create();
    $tags = Tag::factory()->count(3)->create();

    $ticket->tags()->attach($tags->pluck('id'));

    expect($ticket->tags)->toHaveCount(3);
    expect($ticket->tags->first())->toBeInstanceOf(Tag::class);
});

test('ticket can sync tags', function () {
    $ticket = Ticket::factory()->create();
    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();
    $tag3 = Tag::factory()->create();

    $ticket->tags()->attach([$tag1->id, $tag2->id]);
    expect($ticket->tags)->toHaveCount(2);

    $ticket->tags()->sync([$tag2->id, $tag3->id]);
    $ticket->refresh();

    expect($ticket->tags)->toHaveCount(2);
    expect($ticket->tags->pluck('id')->toArray())->toContain($tag2->id, $tag3->id);
    expect($ticket->tags->pluck('id')->toArray())->not->toContain($tag1->id);
});

test('ticket status is cast correctly', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);

    expect($ticket->status)->toBeInstanceOf(TicketStatus::class);
    expect($ticket->status)->toBe(TicketStatus::Open);
});

test('ticket priority is cast correctly', function () {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Urgent]);

    expect($ticket->priority)->toBeInstanceOf(TicketPriority::class);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

test('ticket last_activity_at is cast to datetime', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->last_activity_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('ticket has fillable attributes', function () {
    $data = [
        'ticket_number' => 'QF-999',
        'subject' => 'Test Subject',
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::High,
        'customer_id' => Customer::factory()->create()->id,
        'assigned_to' => User::factory()->create()->id,
        'mailbox_id' => Mailbox::factory()->create()->id,
        'last_activity_at' => now(),
    ];

    $ticket = Ticket::create($data);

    expect($ticket->ticket_number)->toBe('QF-999');
    expect($ticket->subject)->toBe('Test Subject');
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::High);
});

test('ticket uses UUID as primary key', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->id)->toBeString();
    expect(strlen($ticket->id))->toBe(36); // UUID length
});

test('multiple tickets can exist with same customer', function () {
    $customer = Customer::factory()->create();

    $ticket1 = Ticket::factory()->create(['customer_id' => $customer->id]);
    $ticket2 = Ticket::factory()->create(['customer_id' => $customer->id]);
    $ticket3 = Ticket::factory()->create(['customer_id' => $customer->id]);

    expect($customer->tickets)->toHaveCount(3);
});

test('ticket without mailbox has null mailbox_id', function () {
    $ticket = Ticket::factory()->create(['mailbox_id' => null]);

    expect($ticket->mailbox_id)->toBeNull();
    expect($ticket->mailbox)->toBeNull();
});

test('deleting ticket with messages', function () {
    $ticket = Ticket::factory()->create();
    Message::factory()->count(3)->create(['ticket_id' => $ticket->id]);

    $ticket->delete();

    // Messages are cascade deleted via foreign key constraint
    expect(Message::count())->toBe(0);
});

test('ticket can be queried by status', function () {
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->count(2)->create(['status' => TicketStatus::Resolved]);

    $openTickets = Ticket::where('status', TicketStatus::Open)->get();

    expect($openTickets)->toHaveCount(3);
});

test('ticket can be queried by priority', function () {
    Ticket::factory()->count(3)->urgent()->create();
    Ticket::factory()->count(2)->highPriority()->create();
    Ticket::factory()->count(5)->create(['priority' => TicketPriority::Normal]);

    $urgentTickets = Ticket::where('priority', TicketPriority::Urgent)->get();

    expect($urgentTickets)->toHaveCount(3);
});

test('ticket can be queried by assigned agent', function () {
    $agent = User::factory()->create();

    Ticket::factory()->count(3)->create(['assigned_to' => $agent->id]);
    Ticket::factory()->count(2)->create(['assigned_to' => null]);

    $assignedTickets = Ticket::where('assigned_to', $agent->id)->get();

    expect($assignedTickets)->toHaveCount(3);
});

test('ticket can be queried by customer', function () {
    $customer = Customer::factory()->create();

    Ticket::factory()->count(4)->create(['customer_id' => $customer->id]);
    Ticket::factory()->count(2)->create();

    $customerTickets = Ticket::where('customer_id', $customer->id)->get();

    expect($customerTickets)->toHaveCount(4);
});
