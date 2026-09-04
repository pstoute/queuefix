<?php

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\TicketStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->create(['role' => UserRole::Agent]);
});

function statusPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Waiting on customer',
        'slug' => 'waiting-on-customer',
        'color' => '#f59e0b',
        'icon' => 'clock',
        'sort_order' => 60,
        'is_default' => false,
        'is_closed' => false,
        'is_customer_visible' => true,
    ], $overrides);
}

test('fresh installs expose the five protected lifecycle statuses in order', function () {
    actingAs($this->admin);

    get(route('settings.statuses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Statuses/Index')
            ->has('statuses', 5)
            ->where('statuses.0.name', 'Open')
            ->where('statuses.0.is_default', true)
            ->where('statuses.4.name', 'Closed')
            ->where('statuses.4.is_closed', true)
        );

    expect(TicketStatus::query()->where('is_system', true)->count())->toBe(5)
        ->and(TicketStatus::query()->where('is_default', true)->count())->toBe(1);
});

test('only administrators can manage ticket statuses', function () {
    actingAs($this->agent);

    get(route('settings.statuses.index'))->assertForbidden();
    post(route('settings.statuses.store'), statusPayload())->assertForbidden();
});

test('administrators can create and edit a custom status', function () {
    actingAs($this->admin);

    post(route('settings.statuses.store'), statusPayload(['slug' => '']))
        ->assertRedirect()
        ->assertSessionHas('success');

    $status = TicketStatus::query()->where('slug', 'waiting-on-customer')->firstOrFail();
    expect($status->is_system)->toBeFalse()
        ->and($status->is_default)->toBeFalse();

    put(route('settings.statuses.update', $status), statusPayload([
        'name' => 'Customer follow-up',
        'slug' => 'customer-follow-up',
        'color' => '#0ea5e9',
        'icon' => null,
        'sort_order' => 15,
        'is_closed' => true,
        'is_customer_visible' => false,
    ]))->assertRedirect()->assertSessionHas('success');

    $status->refresh();
    expect($status->name)->toBe('Customer follow-up')
        ->and($status->slug)->toBe('customer-follow-up')
        ->and($status->sort_order)->toBe(15)
        ->and($status->is_closed)->toBeTrue()
        ->and($status->is_customer_visible)->toBeFalse();
});

test('changing the default is atomic and the last default cannot be unset', function () {
    actingAs($this->admin);
    $oldDefault = TicketStatus::defaultStatus();
    $custom = app(TicketStatusService::class)->create(statusPayload());

    put(route('settings.statuses.update', $custom), statusPayload(['is_default' => true]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($custom->fresh()->is_default)->toBeTrue()
        ->and($oldDefault->fresh()->is_default)->toBeFalse()
        ->and(TicketStatus::query()->where('is_default', true)->count())->toBe(1);

    put(route('settings.statuses.update', $custom), statusPayload(['is_default' => false]))
        ->assertSessionHasErrors('is_default');

    expect($custom->fresh()->is_default)->toBeTrue()
        ->and(TicketStatus::query()->where('is_default', true)->count())->toBe(1);
});

test('the database guard rejects concurrent-style duplicate defaults', function () {
    expect(fn () => TicketStatus::factory()->create(['is_default' => true]))
        ->toThrow(QueryException::class);

    expect(TicketStatus::query()->where('is_default', true)->count())->toBe(1);
});

test('custom statuses can be archived and restored when unreferenced', function () {
    actingAs($this->admin);
    $status = app(TicketStatusService::class)->create(statusPayload());

    delete(route('settings.statuses.destroy', $status))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(TicketStatus::find($status->id))->toBeNull()
        ->and(TicketStatus::withTrashed()->find($status->id)?->trashed())->toBeTrue();

    patch(route('settings.statuses.restore', $status->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(TicketStatus::find($status->id))->not->toBeNull();
});

test('system default and referenced statuses cannot be archived', function () {
    actingAs($this->admin);
    $system = TicketStatus::defaultStatus();

    delete(route('settings.statuses.destroy', $system))
        ->assertSessionHasErrors('status');

    $custom = app(TicketStatusService::class)->create(statusPayload());
    Ticket::factory()->create(['ticket_status_id' => $custom->id]);

    delete(route('settings.statuses.destroy', $custom))
        ->assertSessionHasErrors('status');

    expect($system->fresh())->not->toBeNull()
        ->and($custom->fresh())->not->toBeNull();
});

test('model policy and the foreign key prevent hard deletion of an assigned status', function () {
    $custom = app(TicketStatusService::class)->create(statusPayload());
    Ticket::factory()->create(['ticket_status_id' => $custom->id]);

    expect(fn () => $custom->forceDelete())->toThrow(LogicException::class);
    expect(fn () => DB::table('ticket_statuses')->where('id', $custom->id)->delete())
        ->toThrow(QueryException::class);
});

test('system statuses accept cosmetic edits but retain protected workflow semantics', function () {
    actingAs($this->admin);
    $system = TicketStatus::defaultStatus();
    $originalSlug = $system->slug;

    put(route('settings.statuses.update', $system), statusPayload([
        'name' => 'New queue',
        'slug' => 'attempted-new-slug',
        'color' => '#8b5cf6',
        'sort_order' => 99,
        'is_default' => true,
        'is_closed' => true,
        'is_customer_visible' => false,
    ]))->assertRedirect()->assertSessionHas('success');

    $system->refresh();
    expect($system->name)->toBe('New queue')
        ->and($system->slug)->toBe($originalSlug)
        ->and($system->color)->toBe('#8b5cf6')
        ->and($system->sort_order)->toBe(99)
        ->and($system->is_closed)->toBeFalse()
        ->and($system->is_customer_visible)->toBeTrue();
});

test('status validation rejects invalid colors and duplicate slugs', function () {
    actingAs($this->admin);
    $existing = TicketStatus::defaultStatus();

    post(route('settings.statuses.store'), statusPayload([
        'slug' => $existing->slug,
        'color' => 'red',
    ]))->assertSessionHasErrors(['slug', 'color']);
});

test('closed statuses cannot become defaults and referenced lifecycle semantics are immutable', function () {
    actingAs($this->admin);
    $status = app(TicketStatusService::class)->create(statusPayload());

    put(route('settings.statuses.update', $status), statusPayload([
        'is_default' => true,
        'is_closed' => true,
    ]))->assertSessionHasErrors('is_default');

    Ticket::factory()->create(['ticket_status_id' => $status->id]);
    put(route('settings.statuses.update', $status), statusPayload([
        'is_closed' => true,
    ]))->assertSessionHasErrors('is_closed');

    expect($status->fresh()->is_closed)->toBeFalse()
        ->and(TicketStatus::defaultStatus()->is_default)->toBeTrue();
});
