<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_cc_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->string('email', 254);
            $table->string('display_name')->nullable();
            $table->string('source', 32);
            $table->string('validation_state', 20)->default('approved');
            $table->nullableMorphs('added_by');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'email']);
            $table->index(['ticket_id', 'validation_state', 'removed_at'], 'ticket_cc_active_idx');
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });

        Schema::create('message_cc_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('message_id');
            $table->uuid('ticket_cc_recipient_id')->nullable();
            $table->string('email', 254);
            $table->string('display_name')->nullable();
            $table->string('source', 32);
            $table->string('validation_state', 20)->default('approved');
            $table->nullableMorphs('created_by');
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'email']);
            $table->index(['message_id', 'validation_state'], 'message_cc_approved_idx');
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('ticket_cc_recipient_id')->references('id')->on('ticket_cc_recipients')->nullOnDelete();
        });

        Schema::create('ticket_cc_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('message_id')->nullable();
            $table->uuid('ticket_cc_recipient_id')->nullable();
            $table->nullableMorphs('actor');
            $table->string('event', 40);
            $table->string('email', 254)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'event']);
            $table->index(['message_id', 'event']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('ticket_cc_recipient_id')->references('id')->on('ticket_cc_recipients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_cc_audits');
        Schema::dropIfExists('message_cc_recipients');
        Schema::dropIfExists('ticket_cc_recipients');
    }
};
