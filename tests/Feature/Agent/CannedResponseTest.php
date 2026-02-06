<?php

use App\Models\CannedResponse;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, put, delete};

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('listing canned responses', function () {
    actingAs($this->user);

    CannedResponse::factory()->count(5)->create(['created_by' => $this->user->id]);

    get(route('agent.canned-responses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/CannedResponses/Index')
            ->has('cannedResponses', 5)
        );
});

test('creating a canned response', function () {
    actingAs($this->user);

    post(route('agent.canned-responses.store'), [
        'title' => 'Welcome Message',
        'body' => 'Thank you for contacting us!',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('canned_responses', [
        'title' => 'Welcome Message',
        'body' => 'Thank you for contacting us!',
        'created_by' => $this->user->id,
    ]);
});

test('updating a canned response', function () {
    actingAs($this->user);

    $response = CannedResponse::factory()->create(['created_by' => $this->user->id]);

    put(route('agent.canned-responses.update', $response), [
        'title' => 'Updated Title',
        'body' => 'Updated body',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('canned_responses', [
        'id' => $response->id,
        'title' => 'Updated Title',
        'body' => 'Updated body',
    ]);
});

test('deleting a canned response', function () {
    actingAs($this->user);

    $response = CannedResponse::factory()->create(['created_by' => $this->user->id]);

    delete(route('agent.canned-responses.destroy', $response))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('canned_responses', [
        'id' => $response->id,
    ]);
});

test('rendering a canned response with variables', function () {
    actingAs($this->user);

    $response = CannedResponse::factory()->create([
        'created_by' => $this->user->id,
        'title' => 'Greeting',
        'body' => 'Hello {{customer_name}}, your ticket {{ticket_number}} has been received.',
    ]);

    get(route('agent.canned-responses.render', $response))
        ->assertOk();
});

test('canned response title is required', function () {
    actingAs($this->user);

    post(route('agent.canned-responses.store'), [
        'body' => 'Test body',
    ])
        ->assertSessionHasErrors('title');
});

test('canned response body is required', function () {
    actingAs($this->user);

    post(route('agent.canned-responses.store'), [
        'title' => 'Test title',
    ])
        ->assertSessionHasErrors('body');
});
