<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, array{name: string, color: string, icon: string, sort_order: int, is_default: bool, is_closed: bool, pauses_sla: bool}> */
    private array $legacyStatuses = [
        'open' => ['name' => 'Open', 'color' => '#22c55e', 'icon' => 'inbox', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'pauses_sla' => false],
        'pending' => ['name' => 'Pending', 'color' => '#f59e0b', 'icon' => 'clock', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'pauses_sla' => true],
        'on_hold' => ['name' => 'On Hold', 'color' => '#6b7280', 'icon' => 'pause', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'pauses_sla' => true],
        'resolved' => ['name' => 'Resolved', 'color' => '#3b82f6', 'icon' => 'circle-check', 'sort_order' => 40, 'is_default' => false, 'is_closed' => true, 'pauses_sla' => false],
        'closed' => ['name' => 'Closed', 'color' => '#9ca3af', 'icon' => 'archive', 'sort_order' => 50, 'is_default' => false, 'is_closed' => true, 'pauses_sla' => false],
    ];

    public function up(): void
    {
        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 7);
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->nullable()->default(null)->unique();
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_customer_visible')->default(true);
            // Kept internal until the dedicated SLA-pause configuration work.
            $table->boolean('pauses_sla')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'sort_order']);
            $table->index(['is_customer_visible', 'deleted_at', 'sort_order'], 'ticket_statuses_customer_index');
        });

        $now = now();
        $statusIds = [];
        foreach ($this->legacyStatuses as $slug => $status) {
            $id = (string) Str::uuid();
            $statusIds[$slug] = $id;
            DB::table('ticket_statuses')->insert([
                'id' => $id,
                'name' => $status['name'],
                'slug' => $slug,
                'color' => $status['color'],
                'icon' => $status['icon'],
                'sort_order' => $status['sort_order'],
                'is_default' => $status['is_default'] ? true : null,
                'is_closed' => $status['is_closed'],
                'is_system' => true,
                'is_customer_visible' => true,
                'pauses_sla' => $status['pauses_sla'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('ticket_status_id')->nullable()->after('subject');
            $table->timestamp('resolved_at')->nullable()->after('last_activity_at');
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
        });

        foreach ($statusIds as $legacyStatus => $statusId) {
            $updates = ['ticket_status_id' => $statusId];
            if ($this->legacyStatuses[$legacyStatus]['is_closed']) {
                $updates['resolved_at'] = DB::raw('updated_at');
                $updates['closed_at'] = DB::raw('updated_at');
            }

            DB::table('tickets')
                ->where('status', $legacyStatus)
                ->update($updates);
        }

        if (DB::table('tickets')->whereNull('ticket_status_id')->exists()) {
            throw new RuntimeException('A legacy ticket status was not mapped.');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('ticket_status_id')->nullable(false)->change();
            $table->foreign('ticket_status_id')->references('id')->on('ticket_statuses')->restrictOnDelete();
            $table->index('ticket_status_id');
            $table->dropIndex('tickets_status_index');
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('status')->nullable()->after('subject');
        });

        $legacySlugs = array_keys($this->legacyStatuses);
        $statuses = DB::table('ticket_statuses')->get(['id', 'slug', 'is_closed']);

        foreach ($statuses as $status) {
            $legacyStatus = in_array($status->slug, $legacySlugs, true)
                ? $status->slug
                : ($status->is_closed ? 'closed' : 'open');

            DB::table('tickets')
                ->where('ticket_status_id', $status->id)
                ->update(['status' => $legacyStatus]);
        }

        if (DB::table('tickets')->whereNull('status')->exists()) {
            throw new RuntimeException('A configurable ticket status could not be reversed.');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('status')->nullable(false)->default('open')->change();
            $table->index('status');
            $table->dropForeign(['ticket_status_id']);
            $table->dropIndex(['ticket_status_id']);
            $table->dropColumn(['ticket_status_id', 'resolved_at', 'closed_at']);
        });

        Schema::dropIfExists('ticket_statuses');
    }
};
