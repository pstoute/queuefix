<?php

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('migration maps every legacy status and reverses configurable records safely', function () {
    $migration = require database_path('migrations/2026_09_04_000001_make_ticket_statuses_configurable.php');
    $migration->down();

    $customer = Customer::factory()->create();
    $now = now();
    $legacyStatuses = ['open', 'pending', 'on_hold', 'resolved', 'closed'];

    foreach ($legacyStatuses as $index => $legacyStatus) {
        DB::table('tickets')->insert([
            'id' => (string) Str::uuid(),
            'ticket_number' => 'MIG-'.($index + 1),
            'subject' => "Legacy {$legacyStatus}",
            'status' => $legacyStatus,
            'priority' => 'normal',
            'customer_id' => $customer->id,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $migration->up();

    foreach ($legacyStatuses as $legacyStatus) {
        $ticket = DB::table('tickets')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.ticket_status_id')
            ->where('tickets.subject', "Legacy {$legacyStatus}")
            ->first(['ticket_statuses.slug', 'tickets.resolved_at', 'tickets.closed_at']);

        expect($ticket?->slug)->toBe($legacyStatus);
        if (in_array($legacyStatus, ['resolved', 'closed'], true)) {
            expect($ticket?->resolved_at)->not->toBeNull()
                ->and($ticket?->closed_at)->not->toBeNull();
        }
    }

    $customStatusId = (string) Str::uuid();
    DB::table('ticket_statuses')->insert([
        'id' => $customStatusId,
        'name' => 'Custom active',
        'slug' => 'custom-active',
        'color' => '#64748b',
        'sort_order' => 70,
        'is_default' => null,
        'is_closed' => false,
        'is_system' => false,
        'is_customer_visible' => true,
        'pauses_sla' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('tickets')->where('subject', 'Legacy open')->update(['ticket_status_id' => $customStatusId]);

    $migration->down();

    expect(DB::table('tickets')->where('subject', 'Legacy open')->value('status'))->toBe('open')
        ->and(DB::table('tickets')->where('subject', 'Legacy closed')->value('status'))->toBe('closed');

    $migration->up();
});
