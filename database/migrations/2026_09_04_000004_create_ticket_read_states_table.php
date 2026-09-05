<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_read_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('user_id');
            $table->timestamp('last_read_at');
            $table->uuid('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
            $table->index(['user_id', 'last_read_at']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('last_read_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at', 'id'], 'messages_ticket_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_ticket_cursor_index');
        });

        Schema::dropIfExists('ticket_read_states');
    }
};
