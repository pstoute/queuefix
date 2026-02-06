<?php

use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, delete};

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('listing tags', function () {
    actingAs($this->user);

    Tag::factory()->count(5)->create();

    get(route('agent.tags.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tags/Index')
            ->has('tags', 5)
        );
});

test('creating a tag', function () {
    actingAs($this->user);

    post(route('agent.tags.store'), [
        'name' => 'Bug',
        'color' => '#ff0000',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tags', [
        'name' => 'Bug',
        'color' => '#ff0000',
    ]);
});

test('tag name is required', function () {
    actingAs($this->user);

    post(route('agent.tags.store'), [
        'color' => '#ff0000',
    ])
        ->assertSessionHasErrors('name');
});

test('attaching tags to ticket', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();
    $tag = Tag::factory()->create();

    post(route('agent.tickets.tags.attach', $ticket), [
        'tag_id' => $tag->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tag_ticket', [
        'ticket_id' => $ticket->id,
        'tag_id' => $tag->id,
    ]);
});

test('attaching multiple tags to ticket', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();
    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();

    post(route('agent.tickets.tags.attach', $ticket), [
        'tag_id' => $tag1->id,
    ]);

    post(route('agent.tickets.tags.attach', $ticket), [
        'tag_id' => $tag2->id,
    ]);

    expect($ticket->tags()->count())->toBe(2);
});

test('detaching tags from ticket', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();
    $tag = Tag::factory()->create();
    $ticket->tags()->attach($tag);

    delete(route('agent.tickets.tags.detach', [$ticket, $tag]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('tag_ticket', [
        'ticket_id' => $ticket->id,
        'tag_id' => $tag->id,
    ]);
});

test('cannot attach same tag twice to ticket', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();
    $tag = Tag::factory()->create();

    $ticket->tags()->attach($tag);

    post(route('agent.tickets.tags.attach', $ticket), [
        'tag_id' => $tag->id,
    ]);

    expect($ticket->tags()->count())->toBe(1);
});
