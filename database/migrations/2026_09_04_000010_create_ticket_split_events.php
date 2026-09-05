<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_split_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('counterpart_ticket_id');
            $table->uuid('actor_id')->nullable();
            $table->string('event_type', 32);
            $table->unsignedInteger('message_count');
            $table->timestamp('occurred_at');

            $table->unique(['ticket_id', 'event_type', 'counterpart_ticket_id'], 'ticket_split_event_unique');
            $table->index(['ticket_id', 'occurred_at']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('counterpart_ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_split_events');
    }
};
