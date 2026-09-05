<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mailbox_id');
            $table->char('idempotency_key', 64);
            $table->uuid('ticket_id')->nullable();
            $table->timestamps();

            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            $table->unique(['mailbox_id', 'idempotency_key']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_receipts');
    }
};
