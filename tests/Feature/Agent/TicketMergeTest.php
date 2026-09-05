<?php

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\SlaPauseInterval;
use App\Models\SlaTimer;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketCcRecipient;
use App\Models\TicketMention;
use App\Models\TicketMergeEvent;
use App\Models\TicketReadState;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Email\EmailProcessorService;
use App\Services\TicketMergeService;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->openStatus = TicketStatus::defaultStatus();
    $this->pendingStatus = $this->ticketStatusAt(20);
    $this->closedStatus = TicketStatus::systemClosedStatus();
});

test('merge atomically preserves history and deduplicates ticket metadata', function () {
    $target = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'last_activity_at' => '2026-01-01 09:00:00',
    ]);
    $source = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'ticket_status_id' => $this->pendingStatus->id,
        'last_activity_at' => '2026-01-01 10:00:00',
    ]);
    $targetMessage = Message::factory()->create([
        'ticket_id' => $target->id,
        'sender_id' => $this->customer->id,
        'created_at' => '2026-01-01 09:00:00',
        'updated_at' => '2026-01-01 09:00:00',
    ]);
    $sourceMessage = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-01 10:00:00',
    ]);
    $attachment = Attachment::create([
        'message_id' => $sourceMessage->id,
        'filename' => 'evidence.txt',
        'path' => 'attachments/evidence.txt',
        'mime_type' => 'text/plain',
        'size' => 42,
    ]);

    $sharedTag = Tag::factory()->create();
    $sourceTag = Tag::factory()->create();
    $target->tags()->attach($sharedTag);
    $source->tags()->attach([$sharedTag->id, $sourceTag->id]);

    $sharedWatcher = User::factory()->create();
    $sourceWatcher = User::factory()->create();
    $target->watchers()->attach($sharedWatcher);
    $source->watchers()->attach([$sharedWatcher->id, $sourceWatcher->id]);

    TicketMention::create([
        'ticket_id' => $source->id,
        'message_id' => $sourceMessage->id,
        'mentioned_user_id' => $sourceWatcher->id,
        'actor_id' => $this->actor->id,
    ]);
    TicketReadState::create([
        'ticket_id' => $source->id,
        'user_id' => $sourceWatcher->id,
        'last_read_at' => '2026-01-01 10:00:00',
        'last_read_message_id' => $sourceMessage->id,
    ]);

    $sourceRecipient = TicketCcRecipient::create([
        'ticket_id' => $source->id,
        'email' => 'participant@example.com',
        'display_name' => 'Participant',
        'source' => 'staff_reply',
        'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
        'approved_at' => now(),
    ]);
    MessageCcRecipient::create([
        'ticket_id' => $source->id,
        'message_id' => $sourceMessage->id,
        'ticket_cc_recipient_id' => $sourceRecipient->id,
        'email' => $sourceRecipient->email,
        'display_name' => $sourceRecipient->display_name,
        'source' => 'staff_reply',
        'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
    ]);

    $timer = SlaTimer::factory()->create([
        'ticket_id' => $source->id,
        'paused_at' => now()->subMinute(),
    ]);
    $pause = SlaPauseInterval::create([
        'sla_timer_id' => $timer->id,
        'started_at' => now()->subMinute(),
    ]);

    actingAs($this->actor);
    get(route('agent.tickets.show', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canMerge', true)
            ->has('mergeCandidates', 1)
            ->where('mergeCandidates.0.id', $source->id)
        );

    post(route('agent.tickets.merge', $target), [
        'merge_ticket_id' => $source->id,
    ])->assertRedirect(route('agent.tickets.show', $target))
        ->assertSessionHas('success');

    expect($targetMessage->fresh()->original_ticket_id)->toBeNull()
        ->and($sourceMessage->fresh()->ticket_id)->toBe($target->id)
        ->and($sourceMessage->fresh()->original_ticket_id)->toBe($source->id)
        ->and($attachment->fresh()->message_id)->toBe($sourceMessage->id)
        ->and(TicketMention::query()->where('message_id', $sourceMessage->id)->value('ticket_id'))->toBe($target->id)
        ->and(MessageCcRecipient::query()->where('message_id', $sourceMessage->id)->value('ticket_id'))->toBe($target->id)
        ->and(TicketReadState::query()->where('ticket_id', $source->id)->exists())->toBeFalse();

    expect($target->fresh()->tags()->pluck('tags.id')->all())
        ->toContain($sharedTag->id, $sourceTag->id)
        ->and($source->fresh()->tags()->count())->toBe(0)
        ->and($target->fresh()->watchers()->pluck('users.id')->all())
        ->toContain($sharedWatcher->id, $sourceWatcher->id)
        ->and($target->watchers()->count())->toBe(2)
        ->and($source->fresh()->watchers()->count())->toBe(0);

    $source->refresh();
    expect($source->merged_into_ticket_id)->toBe($target->id)
        ->and($source->merged_by)->toBe($this->actor->id)
        ->and($source->merged_at)->not->toBeNull()
        ->and($source->status->is($this->closedStatus))->toBeTrue()
        ->and($timer->fresh()->resolved_at)->not->toBeNull()
        ->and($timer->fresh()->paused_at)->toBeNull()
        ->and($pause->fresh()->ended_at)->not->toBeNull();

    $this->assertDatabaseHas('ticket_cc_recipients', [
        'ticket_id' => $target->id,
        'email' => 'participant@example.com',
        'source' => 'ticket_merge',
    ]);
    $this->assertDatabaseHas('ticket_merge_events', [
        'ticket_id' => $source->id,
        'counterpart_ticket_id' => $target->id,
        'event_type' => TicketMergeEvent::SOURCE_MERGED,
    ]);
    $this->assertDatabaseHas('ticket_merge_events', [
        'ticket_id' => $target->id,
        'counterpart_ticket_id' => $source->id,
        'event_type' => TicketMergeEvent::TARGET_RECEIVED,
    ]);

    get(route('agent.tickets.show', $source))
        ->assertRedirect(route('agent.tickets.show', $target));
    post(route('agent.tickets.reply', $source), [
        'body' => 'A merged source must remain immutable.',
        'type' => 'internal_note',
    ])->assertForbidden();
    get(route('agent.tickets.show', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ticket.merge_events', 1)
            ->where('ticket.merge_events.0.counterpart_ticket.id', $source->id)
            ->has('ticket.messages', 2)
            ->where('ticket.messages.1.original_ticket.id', $source->id)
        );
    get(route('agent.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $target->id)
        );

    actingAs($this->customer, 'customer');
    get(route('customer.tickets.show', $source))
        ->assertRedirect(route('customer.tickets.show', $target));
    get(route('customer.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $target->id)
        );
});

test('merge rejects self merges, prior sources, prior targets, and different customers', function () {
    $target = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $other = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $differentCustomer = Ticket::factory()->create();

    actingAs($this->actor);

    post(route('agent.tickets.merge', $target), ['merge_ticket_id' => $target->id])
        ->assertSessionHasErrors('merge_ticket_id');
    post(route('agent.tickets.merge', $target), ['merge_ticket_id' => $differentCustomer->id])
        ->assertSessionHasErrors('merge_ticket_id');

    post(route('agent.tickets.merge', $target), ['merge_ticket_id' => $source->id])
        ->assertRedirect(route('agent.tickets.show', $target));
    post(route('agent.tickets.merge', $other), ['merge_ticket_id' => $source->id])
        ->assertSessionHasErrors('merge_ticket_id');
    post(route('agent.tickets.merge', $source), ['merge_ticket_id' => $other->id])
        ->assertSessionHasErrors('merge_ticket_id');

    expect($differentCustomer->fresh()->merged_into_ticket_id)->toBeNull()
        ->and($other->fresh()->merged_into_ticket_id)->toBeNull();
});

test('merge rolls back every mutation when an audit insert fails', function () {
    $target = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $message = Message::factory()->create([
        'ticket_id' => $source->id,
        'sender_id' => $this->customer->id,
    ]);
    $tag = Tag::factory()->create();
    $watcher = User::factory()->create();
    $source->tags()->attach($tag);
    $source->watchers()->attach($watcher);

    TicketMergeEvent::create([
        'ticket_id' => $target->id,
        'counterpart_ticket_id' => $source->id,
        'actor_id' => $this->actor->id,
        'event_type' => TicketMergeEvent::TARGET_RECEIVED,
        'occurred_at' => now()->subMinute(),
    ]);

    expect(fn () => app(TicketMergeService::class)->merge($source, $target, $this->actor))
        ->toThrow(QueryException::class);

    expect($message->fresh()->ticket_id)->toBe($source->id)
        ->and($message->fresh()->original_ticket_id)->toBeNull()
        ->and($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and($source->status->is($this->openStatus))->toBeTrue()
        ->and($source->tags()->whereKey($tag->id)->exists())->toBeTrue()
        ->and($source->watchers()->whereKey($watcher->id)->exists())->toBeTrue()
        ->and($target->tags()->whereKey($tag->id)->exists())->toBeFalse()
        ->and(TicketMergeEvent::query()->where('event_type', TicketMergeEvent::SOURCE_MERGED)->exists())->toBeFalse();
});

test('inactive staff and customer sessions cannot merge tickets', function () {
    $target = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $source = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $inactive = User::factory()->create(['is_active' => false]);

    actingAs($inactive);
    post(route('agent.tickets.merge', $target), ['merge_ticket_id' => $source->id])
        ->assertForbidden();

    actingAs($this->customer, 'customer');
    post(route('agent.tickets.merge', $target), ['merge_ticket_id' => $source->id])
        ->assertForbidden();

    expect($source->fresh()->merged_into_ticket_id)->toBeNull();
});

test('inbound mail addressed to an archived source continues on the canonical ticket', function () {
    $mailbox = Mailbox::factory()->create();
    $target = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'mailbox_id' => $mailbox->id,
    ]);
    $source = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'mailbox_id' => $mailbox->id,
    ]);
    app(TicketMergeService::class)->merge($source, $target, $this->actor);

    $result = app(EmailProcessorService::class)->processInboundEmail([
        'from_email' => $this->customer->email,
        'from_name' => $this->customer->name,
        'to_email' => $mailbox->email,
        'subject' => "Re: [{$source->ticket_number}] Duplicate request",
        'body_text' => 'Following up after the merge.',
        'message_id' => '<after-merge@example.com>',
    ], $mailbox);

    expect($result->id)->toBe($target->id)
        ->and(Message::query()->where('message_id', '<after-merge@example.com>')->value('ticket_id'))->toBe($target->id)
        ->and($source->fresh()->status->is($this->closedStatus))->toBeTrue();
});

test('merge history records cannot be updated or deleted through the model', function () {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $counterpart = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $event = TicketMergeEvent::create([
        'ticket_id' => $ticket->id,
        'counterpart_ticket_id' => $counterpart->id,
        'actor_id' => $this->actor->id,
        'event_type' => TicketMergeEvent::TARGET_RECEIVED,
        'occurred_at' => now(),
    ]);

    expect(fn () => $event->update(['occurred_at' => now()->addMinute()]))
        ->toThrow(\LogicException::class)
        ->and(fn () => $event->delete())
        ->toThrow(\LogicException::class);
});
