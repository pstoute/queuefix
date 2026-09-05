<?php

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketMention;
use App\Models\User;
use App\Notifications\TicketMentionNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

test('internal notes create one normalized mention for each authorized active recipient', function () {
    Notification::fake();

    $actor = User::factory()->create(['name' => 'Alex Author', 'handle' => 'alex']);
    $recipient = User::factory()->create(['name' => 'Sam Agent', 'handle' => 'sam']);
    $disabled = User::factory()->create(['name' => 'Off Agent', 'handle' => 'off', 'is_active' => false]);
    $ticket = Ticket::factory()->create();

    actingAs($actor);

    post(route('agent.tickets.reply', $ticket), [
        'body' => '@SAM @sam @missing @off @alex <script>alert("unsafe")</script>',
        'type' => MessageType::InternalNote->value,
    ])->assertRedirect();

    $message = Message::query()->where('ticket_id', $ticket->id)->sole();
    expect($message->body_text)->toContain('<script>')
        ->and($message->body_html)->toBeNull();

    $mention = TicketMention::query()->sole();
    expect($mention->mentioned_user_id)->toBe($recipient->id)
        ->and($mention->actor_id)->toBe($actor->id)
        ->and($mention->ticket_id)->toBe($ticket->id)
        ->and($mention->notified_at)->not->toBeNull()
        ->and($mention->removed_at)->toBeNull();

    Notification::assertSentTo(
        $recipient,
        TicketMentionNotification::class,
        fn (TicketMentionNotification $notification, array $channels) => $channels === ['database', 'mail'],
    );
    Notification::assertNotSentTo([$actor, $disabled], TicketMentionNotification::class);

    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.messages.0.body_html', null)
            ->where('ticket.messages.0.body_text', '@SAM @sam @missing @off @alex <script>alert("unsafe")</script>')
            ->has('ticket.messages.0.mentions', 1)
            ->where('ticket.messages.0.mentions.0.mentioned_user.handle', 'sam')
            ->has('mentionableUsers', 1)
            ->where('mentionableUsers.0.id', $recipient->id)
        );
});

test('public replies never create mention records or mention notifications', function () {
    Notification::fake();

    $actor = User::factory()->create(['handle' => 'author']);
    $recipient = User::factory()->create(['handle' => 'recipient']);
    $ticket = Ticket::factory()->create();

    actingAs($actor);

    post(route('agent.tickets.reply', $ticket), [
        'body' => '@recipient This is a public reply.',
        'type' => MessageType::Reply->value,
    ])->assertRedirect();

    $this->assertDatabaseCount('ticket_mentions', 0);
    Notification::assertNotSentTo($recipient, TicketMentionNotification::class);
});

test('active staff without ticket access are not converted into mentions or typeahead options', function () {
    Notification::fake();

    $actor = User::factory()->create(['handle' => 'author']);
    $authorized = User::factory()->create(['handle' => 'authorized']);
    $unauthorized = User::factory()->create(['handle' => 'unauthorized']);
    $ticket = Ticket::factory()->create();

    Gate::before(function (User $user, string $ability) use ($unauthorized): ?bool {
        return $ability === 'view' && $user->is($unauthorized) ? false : null;
    });

    actingAs($actor);

    post(route('agent.tickets.reply', $ticket), [
        'body' => '@authorized please help; @unauthorized should not be linked.',
        'type' => MessageType::InternalNote->value,
    ])->assertRedirect();

    expect(TicketMention::query()->pluck('mentioned_user_id')->all())->toBe([$authorized->id]);
    Notification::assertSentTo($authorized, TicketMentionNotification::class);
    Notification::assertNotSentTo($unauthorized, TicketMentionNotification::class);

    get(route('agent.tickets.show', $ticket))
        ->assertInertia(fn ($page) => $page
            ->has('mentionableUsers', 1)
            ->where('mentionableUsers.0.id', $authorized->id)
        );
});

test('editing an internal note synchronizes mentions without duplicate notifications', function () {
    Notification::fake();

    $actor = User::factory()->create(['handle' => 'author']);
    $first = User::factory()->create(['handle' => 'first']);
    $second = User::factory()->create(['handle' => 'second']);
    $third = User::factory()->create(['handle' => 'third']);
    $ticket = Ticket::factory()->create();

    actingAs($actor);
    post(route('agent.tickets.reply', $ticket), [
        'body' => '@first and @second please review.',
        'type' => MessageType::InternalNote->value,
    ])->assertRedirect();

    $message = Message::query()->where('ticket_id', $ticket->id)->sole();
    Notification::assertSentTo([$first, $second], TicketMentionNotification::class);

    Notification::fake();
    patch(route('agent.tickets.messages.internal-note.update', [$ticket, $message]), [
        'body' => '@first and @second please review the revision.',
    ])->assertRedirect();
    Notification::assertNothingSent();

    patch(route('agent.tickets.messages.internal-note.update', [$ticket, $message]), [
        'body' => '@second and @third @third please review instead.',
    ])->assertRedirect();

    Notification::assertSentTo($third, TicketMentionNotification::class);
    Notification::assertNotSentTo([$first, $second], TicketMentionNotification::class);
    expect(TicketMention::query()->whereNull('removed_at')->pluck('mentioned_user_id')->all())
        ->toContain($second->id, $third->id)
        ->not->toContain($first->id)
        ->and(TicketMention::query()->count())->toBe(3);

    Notification::fake();
    patch(route('agent.tickets.messages.internal-note.update', [$ticket, $message]), [
        'body' => '@first @second and @third please review.',
    ])->assertRedirect();
    Notification::assertNothingSent();
    expect(TicketMention::query()->whereNull('removed_at')->count())->toBe(3);
});

test('only an internal note author can edit that note', function () {
    $author = User::factory()->create();
    $otherAgent = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $note = Message::factory()->internalNote()->create([
        'ticket_id' => $ticket->id,
        'sender_id' => $author->id,
    ]);
    $reply = Message::factory()->fromAgent()->create([
        'ticket_id' => $ticket->id,
        'sender_id' => $author->id,
    ]);

    actingAs($otherAgent);
    patch(route('agent.tickets.messages.internal-note.update', [$ticket, $note]), [
        'body' => 'Unauthorized edit',
    ])->assertForbidden();

    actingAs($author);
    patch(route('agent.tickets.messages.internal-note.update', [$ticket, $reply]), [
        'body' => 'Not an internal note',
    ])->assertStatus(422);
});

test('mention deep links reauthorize access and target the exact message', function () {
    Notification::fake();

    $actor = User::factory()->create(['handle' => 'author']);
    $recipient = User::factory()->create(['handle' => 'recipient']);
    $ticket = Ticket::factory()->create();

    actingAs($actor);
    post(route('agent.tickets.reply', $ticket), [
        'body' => '@recipient exact link',
        'type' => MessageType::InternalNote->value,
    ])->assertRedirect();

    $message = Message::query()->where('ticket_id', $ticket->id)->sole();
    $deepLink = route('agent.tickets.messages.show', [$ticket, $message]);

    actingAs($recipient);
    get($deepLink)->assertRedirect(route('agent.tickets.show', $ticket)."#message-{$message->id}");

    $recipient->update(['is_active' => false]);
    actingAs($recipient->fresh());
    get($deepLink)->assertForbidden();
});

test('new staff receive normalized unique handles', function () {
    $first = User::factory()->create(['name' => 'Jane Doe']);
    $second = User::factory()->create(['name' => 'Jane Doe']);
    $custom = User::factory()->create(['name' => 'Custom', 'handle' => '@Mixed.Name']);

    expect($first->handle)->toBe('jane-doe')
        ->and($second->handle)->toBe('jane-doe-2')
        ->and($custom->handle)->toBe('mixed-name');
});
