<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('merged_into_ticket_id')->nullable()->after('closed_at');
            $table->timestamp('merged_at')->nullable()->after('merged_into_ticket_id');
            $table->uuid('merged_by')->nullable()->after('merged_at');

            $table->index('merged_into_ticket_id');
            $table->index('merged_by');
            $table->foreign('merged_into_ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('merged_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('original_ticket_id')->nullable()->after('ticket_id');
            $table->index('original_ticket_id');
            $table->foreign('original_ticket_id')->references('id')->on('tickets')->restrictOnDelete();
        });

        Schema::create('ticket_merge_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('counterpart_ticket_id');
            $table->uuid('actor_id')->nullable();
            $table->string('event_type', 32);
            $table->timestamp('occurred_at');

            $table->unique(['ticket_id', 'event_type', 'counterpart_ticket_id'], 'ticket_merge_event_unique');
            $table->index(['ticket_id', 'occurred_at']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('counterpart_ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_merge_events');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['original_ticket_id']);
            $table->dropIndex(['original_ticket_id']);
            $table->dropColumn('original_ticket_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['merged_into_ticket_id']);
            $table->dropForeign(['merged_by']);
            $table->dropIndex(['merged_into_ticket_id']);
            $table->dropIndex(['merged_by']);
            $table->dropColumn(['merged_into_ticket_id', 'merged_at', 'merged_by']);
        });
    }
};
