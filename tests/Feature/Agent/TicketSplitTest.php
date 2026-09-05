<?php

use App\Enums\TicketPriority;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\SlaPolicy;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketCcRecipient;
use App\Models\TicketMention;
use App\Models\TicketReadState;
use App\Models\TicketSplitEvent;
use App\Models\User;
use App\Services\Email\EmailProcessorService;
use App\Services\TicketSplitService;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->customer = Customer::factory()->create();
});

test('split atomically moves selected history and safe ticket context', function () {
    SlaPolicy::factory()->forPriority(TicketPriority::High)->create();
    $department = Department::create(['name' => 'Support']);
    $mailbox = Mailbox::factory()->create(['department_id' => $department->id]);
    $source = Ticket::factory()->highPriority()->create([
        'customer_id' => $this->customer->id,
        'mailbox_id' => $mailbox->id,
        'department_id' => $department->id,
    ]);
    $priorOrigin = Ticket::factory()->create(['customer_id' => $this->customer->id]);

    $first = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
        'created_at' => '2026-01-01 09:00:00',
        'updated_at' => '2026-01-01 09:00:00',
    ]);
    $unselected = Message::factory()->internalNote()->create([
        'ticket_id' => $source->id,
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-01 10:00:00',
    ]);
    $last = Message::factory()->fromAgent()->create([
        'ticket_id' => $source->id,
        'original_ticket_id' => $priorOrigin->id,
        'created_at' => '2026-01-01 11:00:00',
        'updated_at' => '2026-01-01 11:00:00',
    ]);
    $attachment = Attachment::create([
        'message_id' => $first->id,
        'filename' => 'context.txt',
        'path' => 'attachments/context.txt',
        'mime_type' => 'text/plain',
        'size' => 42,
    ]);

    $tags = Tag::factory()->count(2)->create();
    $source->tags()->attach($tags->modelKeys());
    TicketMention::create([
        'ticket_id' => $source->id,
        'message_id' => $last->id,
        'mentioned_user_id' => $this->actor->id,
        'actor_id' => $last->sender_id,
    ]);
    TicketReadState::create([
        'ticket_id' => $source->id,
        'user_id' => $this->actor->id,
        'last_read_at' => now(),
        'last_read_message_id' => $last->id,
    ]);
    $recipient = TicketCcRecipient::create([
        'ticket_id' => $source->id,
        'email' => 'participant@example.com',
        'source' => 'staff_reply',
        'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
        'approved_at' => now(),
    ]);
    MessageCcRecipient::create([
        'ticket_id' => $source->id,
        'message_id' => $first->id,
        'ticket_cc_recipient_id' => $recipient->id,
        'email' => $recipient->email,
        'source' => 'staff_reply',
        'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
    ]);

    actingAs($this->actor);
    get(route('agent.tickets.show', $source))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSplit', true));

    $response = post(route('agent.tickets.split', $source), [
        'subject' => 'Independent follow-up',
        'message_ids' => [$last->id, $first->id],
    ])->assertSessionHas('success');

    $newTicket = Ticket::query()->where('subject', 'Independent follow-up')->sole();
    $response->assertRedirect(route('agent.tickets.show', $newTicket));

    expect($newTicket->customer_id)->toBe($source->customer_id)
        ->and($newTicket->mailbox_id)->toBe($source->mailbox_id)
        ->and($newTicket->department_id)->toBe($source->department_id)
        ->and($newTicket->priority)->toBe(TicketPriority::High)
        ->and($newTicket->assigned_to)->toBeNull()
        ->and($newTicket->tags()->pluck('tags.id')->sort()->values()->all())
        ->toBe($tags->modelKeys());

    expect($newTicket->messages()->orderBy('created_at')->pluck('id')->all())
        ->toBe([$first->id, $last->id])
        ->and($unselected->fresh()->ticket_id)->toBe($source->id)
        ->and($first->fresh()->original_ticket_id)->toBe($source->id)
        ->and($last->fresh()->original_ticket_id)->toBe($priorOrigin->id)
        ->and($attachment->fresh()->message_id)->toBe($first->id)
        ->and(TicketMention::query()->where('message_id', $last->id)->value('ticket_id'))->toBe($newTicket->id)
        ->and(MessageCcRecipient::query()->where('message_id', $first->id)->value('ticket_id'))->toBe($newTicket->id)
        ->and(MessageCcRecipient::query()->where('message_id', $first->id)->value('ticket_cc_recipient_id'))->toBeNull()
        ->and(TicketReadState::query()->where('ticket_id', $source->id)->exists())->toBeFalse();

    expect($newTicket->slaTimer)->not->toBeNull()
        ->and($newTicket->slaTimer->first_responded_at)->not->toBeNull();

    $this->assertDatabaseHas('ticket_split_events', [
        'ticket_id' => $source->id,
        'counterpart_ticket_id' => $newTicket->id,
        'event_type' => TicketSplitEvent::SOURCE_SPLIT,
        'message_count' => 2,
    ]);
    $this->assertDatabaseHas('ticket_split_events', [
        'ticket_id' => $newTicket->id,
        'counterpart_ticket_id' => $source->id,
        'event_type' => TicketSplitEvent::NEW_TICKET_CREATED,
        'message_count' => 2,
    ]);

    get(route('agent.tickets.show', $source))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.split_events', 1)
            ->where('ticket.split_events.0.counterpart_ticket.id', $newTicket->id)
            ->has('ticket.messages', 1)
        );
    get(route('agent.tickets.show', $newTicket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.split_events', 1)
            ->where('ticket.split_events.0.counterpart_ticket.id', $source->id)
            ->has('ticket.messages', 2)
        );

    actingAs($this->customer, 'customer');
    get(route('customer.tickets.show', $source))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.messages', 0)
            ->missing('ticket.split_events')
        );
    get(route('customer.tickets.show', $newTicket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.messages', 2)
            ->missing('ticket.split_events')
        );

    $otherCustomer = Customer::factory()->create();
    actingAs($otherCustomer, 'customer');
    get(route('customer.tickets.show', $newTicket))->assertForbidden();
});

test('split rejects foreign, duplicate, and missing message selections without moving data', function () {
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $other = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $sourceMessage = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
    ]);
    $foreignMessage = Message::factory()->create([
        'ticket_id' => $other->id,
        'sender_id' => $this->customer->id,
    ]);

    actingAs($this->actor);
    post(route('agent.tickets.split', $source), [
        'subject' => 'Invalid split',
        'message_ids' => [$sourceMessage->id, $foreignMessage->id],
    ])->assertSessionHasErrors('message_ids');
    post(route('agent.tickets.split', $source), [
        'subject' => 'Duplicate split',
        'message_ids' => [$sourceMessage->id, $sourceMessage->id],
    ])->assertSessionHasErrors('message_ids.1');
    post(route('agent.tickets.split', $source), [
        'subject' => 'Empty split',
        'message_ids' => [],
    ])->assertSessionHasErrors('message_ids');

    expect($sourceMessage->fresh()->ticket_id)->toBe($source->id)
        ->and($sourceMessage->fresh()->original_ticket_id)->toBeNull()
        ->and($foreignMessage->fresh()->ticket_id)->toBe($other->id)
        ->and(Ticket::query()->whereIn('subject', ['Invalid split', 'Duplicate split', 'Empty split'])->exists())->toBeFalse();
});

test('inactive staff, customers, and merged source tickets cannot be split', function () {
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $message = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
    ]);
    $inactive = User::factory()->create(['is_active' => false]);

    actingAs($inactive);
    post(route('agent.tickets.split', $source), [
        'subject' => 'Denied',
        'message_ids' => [$message->id],
    ])->assertForbidden();

    actingAs($this->customer, 'customer');
    post(route('agent.tickets.split', $source), [
        'subject' => 'Denied',
        'message_ids' => [$message->id],
    ])->assertForbidden();

    $source->update(['merged_into_ticket_id' => Ticket::factory()->create(['customer_id' => $this->customer->id])->id]);
    actingAs($this->actor);
    post(route('agent.tickets.split', $source), [
        'subject' => 'Denied',
        'message_ids' => [$message->id],
    ])->assertForbidden();

    expect($message->fresh()->ticket_id)->toBe($source->id)
        ->and(Ticket::query()->where('subject', 'Denied')->exists())->toBeFalse();
});

test('split rolls back ticket creation and message movement when audit creation fails', function () {
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $message = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
    ]);
    $tag = Tag::factory()->create();
    $source->tags()->attach($tag);
    $eventName = 'eloquent.creating: '.TicketSplitEvent::class;
    Event::listen($eventName, fn () => throw new \RuntimeException('Forced audit failure.'));

    try {
        expect(fn () => app(TicketSplitService::class)->split(
            $source,
            [$message->id],
            'Rolled back split',
            $this->actor,
        ))->toThrow(\RuntimeException::class, 'Forced audit failure.');
    } finally {
        Event::forget($eventName);
    }

    expect($message->fresh()->ticket_id)->toBe($source->id)
        ->and($message->fresh()->original_ticket_id)->toBeNull()
        ->and(Ticket::query()->where('subject', 'Rolled back split')->exists())->toBeFalse()
        ->and(TicketSplitEvent::query()->exists())->toBeFalse()
        ->and($source->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

test('future mail follows moved message references while source-number mail stays on source', function () {
    $mailbox = Mailbox::factory()->create();
    $source = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'mailbox_id' => $mailbox->id,
    ]);
    $selected = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
        'message_id' => '<selected@example.com>',
    ]);
    $newTicket = app(TicketSplitService::class)->split(
        $source,
        [$selected->id],
        'Split thread',
        $this->actor,
    );

    $byReference = app(EmailProcessorService::class)->processInboundEmail([
        'from_email' => $this->customer->email,
        'from_name' => $this->customer->name,
        'to_email' => $mailbox->email,
        'subject' => 'Re: selected message',
        'body_text' => 'This belongs to the split branch.',
        'message_id' => '<split-follow-up@example.com>',
        'in_reply_to' => '<selected@example.com>',
    ], $mailbox);
    $bySourceNumber = app(EmailProcessorService::class)->processInboundEmail([
        'from_email' => $this->customer->email,
        'from_name' => $this->customer->name,
        'to_email' => $mailbox->email,
        'subject' => "Re: [{$source->ticket_number}] original branch",
        'body_text' => 'This belongs to the source branch.',
        'message_id' => '<source-follow-up@example.com>',
    ], $mailbox);

    expect($byReference->id)->toBe($newTicket->id)
        ->and($bySourceNumber->id)->toBe($source->id)
        ->and(Message::query()->where('message_id', '<split-follow-up@example.com>')->value('ticket_id'))->toBe($newTicket->id)
        ->and(Message::query()->where('message_id', '<source-follow-up@example.com>')->value('ticket_id'))->toBe($source->id);
});

test('split history records cannot be updated or deleted through the model', function () {
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $newTicket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $event = TicketSplitEvent::create([
        'ticket_id' => $source->id,
        'counterpart_ticket_id' => $newTicket->id,
        'actor_id' => $this->actor->id,
        'event_type' => TicketSplitEvent::SOURCE_SPLIT,
        'message_count' => 1,
        'occurred_at' => now(),
    ]);

    expect(fn () => $event->update(['message_count' => 2]))
        ->toThrow(LogicException::class)
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class);
});
