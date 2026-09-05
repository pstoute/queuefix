<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_email_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mailbox_id');
            $table->char('idempotency_key', 64);
            $table->uuid('claim_token');
            $table->timestamp('lease_expires_at');
            $table->timestamp('retry_not_before')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamps();

            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->unique(['mailbox_id', 'idempotency_key']);
            $table->index(['lease_expires_at', 'retry_not_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_claims');
    }
};
