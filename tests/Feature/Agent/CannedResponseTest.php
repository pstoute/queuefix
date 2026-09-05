<?php

use App\Models\CannedResponse;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('agents list only the canned responses they manage while admins list all responses', function () {
    $otherUser = User::factory()->create();
    CannedResponse::factory()->count(2)->create(['created_by' => $this->user->id]);
    CannedResponse::factory()->create(['created_by' => $otherUser->id]);

    actingAs($this->user);
    get(route('agent.canned-responses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/CannedResponses/Index')
            ->has('cannedResponses', 2)
        );

    $admin = User::factory()->admin()->create();
    actingAs(User::query()->findOrFail($admin->id));
    get(route('settings.canned-responses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cannedResponses', 3));
});

test('creating a canned response validates placeholders and applies availability defaults', function () {
    actingAs($this->user);

    post(route('agent.canned-responses.store'), [
        'title' => 'Welcome Message',
        'body' => 'Hello {{customer.name}}, ticket {{ticket.ticket_number}} is with {{assignee.name}}.',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('canned_responses', [
        'title' => 'Welcome Message',
        'is_active' => true,
        'visibility' => CannedResponse::VISIBILITY_ALL_AGENTS,
        'created_by' => $this->user->id,
    ]);

    post(route('agent.canned-responses.store'), [
        'title' => 'Invalid template',
        'body' => 'Hello {{customer.first_name}}.',
    ])->assertSessionHasErrors([
        'body' => 'Unknown placeholder(s): {{customer.first_name}}.',
    ]);
});

test('owners can update and delete their canned responses', function () {
    actingAs($this->user);
    $response = CannedResponse::factory()->create(['created_by' => $this->user->id]);

    put(route('agent.canned-responses.update', $response), [
        'title' => 'Updated Title',
        'body' => 'Updated body for {{current_date}}',
        'is_active' => false,
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('canned_responses', [
        'id' => $response->id,
        'title' => 'Updated Title',
        'is_active' => false,
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ]);

    delete(route('agent.canned-responses.destroy', $response))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('canned_responses', ['id' => $response->id]);
});

test('agents cannot manage another agents private responses', function () {
    actingAs($this->user);
    $response = CannedResponse::factory()->create([
        'created_by' => User::factory(),
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ]);

    put(route('agent.canned-responses.update', $response), [
        'title' => '',
        'body' => '',
    ])->assertForbidden();

    delete(route('agent.canned-responses.destroy', $response))->assertForbidden();
});

test('picker search returns only active authorized responses matching title or body', function () {
    actingAs($this->user);
    $ticket = Ticket::factory()->create();
    $otherUser = User::factory()->create();
    $global = CannedResponse::factory()->create([
        'created_by' => $otherUser,
        'title' => 'Billing follow-up',
        'body' => 'Global result',
    ]);
    $private = CannedResponse::factory()->create([
        'created_by' => $this->user,
        'title' => 'My response',
        'body' => 'A billing-only private result',
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ]);
    CannedResponse::factory()->create([
        'created_by' => $otherUser,
        'title' => 'Private billing response',
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ]);
    CannedResponse::factory()->create([
        'created_by' => $this->user,
        'title' => 'Inactive billing response',
        'is_active' => false,
    ]);
    CannedResponse::factory()->create([
        'created_by' => $this->user,
        'title' => 'Unrelated response',
    ]);

    $this->getJson(route('agent.tickets.canned-responses.index', [
        'ticket' => $ticket,
        'search' => 'BILLING',
    ]))
        ->assertOk()
        ->assertJsonCount(2, 'canned_responses')
        ->assertJsonPath('canned_responses.0.id', $global->id)
        ->assertJsonPath('canned_responses.1.id', $private->id);
});

test('rendering replaces every allowlisted placeholder with insertion-time ticket context', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    actingAs($this->user);
    $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);
    $department = Department::query()->create(['name' => 'Billing']);
    $assignee = User::factory()->create(['name' => 'Grace Hopper']);
    $ticket = Ticket::factory()->create([
        'ticket_number' => 'QF-4242',
        'subject' => 'Invoice question',
        'customer_id' => $customer,
        'department_id' => $department,
        'assigned_to' => $assignee,
    ]);
    $response = CannedResponse::factory()->create([
        'created_by' => $this->user,
        'body' => '{{customer.name}} | {{ticket.ticket_number}} | {{ticket.subject}} | {{department.name}} | {{assignee.name}} | {{current_date}}',
    ]);

    $this->postJson(route('agent.tickets.canned-responses.render', [$ticket, $response]))
        ->assertOk()
        ->assertJsonPath('body', 'Ada Lovelace | QF-4242 | Invoice question | Billing | Grace Hopper | Sep 4, 2026');

    Carbon::setTestNow();
});

test('unavailable responses cannot be rendered and inactive agents cannot use the picker', function () {
    $ticket = Ticket::factory()->create();
    $private = CannedResponse::factory()->create([
        'created_by' => User::factory(),
        'visibility' => CannedResponse::VISIBILITY_CREATOR_ONLY,
    ]);
    actingAs($this->user);

    $this->postJson(route('agent.tickets.canned-responses.render', [$ticket, $private]))
        ->assertNotFound();

    $inactive = User::factory()->create(['is_active' => false]);
    actingAs($inactive);
    $this->getJson(route('agent.tickets.canned-responses.index', $ticket))
        ->assertForbidden();
});

test('unknown placeholders in legacy templates return validation errors instead of blank text', function () {
    actingAs($this->user);
    $ticket = Ticket::factory()->create();
    $response = CannedResponse::factory()->create([
        'created_by' => $this->user,
        'body' => 'Hello {{customer.nickname}}.',
    ]);

    $this->postJson(route('agent.tickets.canned-responses.render', [$ticket, $response]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body')
        ->assertJsonPath('errors.body.0', 'Unknown placeholder(s): {{customer.nickname}}.');
});

test('ticket and template markup remains editable plain text and only the final reply body is persisted', function () {
    actingAs($this->user);
    $customer = Customer::factory()->create(['name' => '<script>customer()</script>']);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer,
        'subject' => '<img src=x onerror=subject()>',
    ]);
    $response = CannedResponse::factory()->create([
        'created_by' => $this->user,
        'body' => '<b>Template</b> {{customer.name}} {{ticket.subject}}',
    ]);
    $rendered = '<b>Template</b> <script>customer()</script> <img src=x onerror=subject()>';

    $this->postJson(route('agent.tickets.canned-responses.render', [$ticket, $response]))
        ->assertOk()
        ->assertJsonPath('body', $rendered);

    post(route('agent.tickets.reply', $ticket), [
        'body' => $rendered.' edited by the agent',
        'type' => 'reply',
    ])->assertRedirect();

    $message = Message::query()->where('ticket_id', $ticket->id)->sole();
    expect($message->body_text)->toBe($rendered.' edited by the agent')
        ->and($message->body_html)->toBeNull();

    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.messages.0.body_text', $rendered.' edited by the agent')
            ->where('ticket.messages.0.body_html', null)
        );
});

test('canned response title and body are required', function () {
    actingAs($this->user);

    post(route('agent.canned-responses.store'), ['body' => 'Test body'])
        ->assertSessionHasErrors('title');
    post(route('agent.canned-responses.store'), ['title' => 'Test title'])
        ->assertSessionHasErrors('body');
});
