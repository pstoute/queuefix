<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->timestamp('last_fetch_attempted_at')->nullable();
            $table->timestamp('last_fetch_succeeded_at')->nullable();
            $table->string('provider_cursor', 512)->nullable();
            $table->unsignedInteger('consecutive_fetch_failures')->default(0);
            $table->string('last_fetch_error_category', 32)->nullable();
            $table->string('last_fetch_error_code', 64)->nullable();
            $table->string('last_fetch_error_message', 255)->nullable();
            $table->timestamp('next_fetch_at')->nullable();
            $table->timestamp('fetch_queued_at')->nullable();
            $table->timestamp('fetch_started_at')->nullable();
            $table->unsignedInteger('pending_inbound_count')->default(0);
            $table->unsignedInteger('consecutive_processing_failures')->default(0);
            $table->timestamp('last_processing_succeeded_at')->nullable();
            $table->timestamp('last_processing_failed_at')->nullable();
            $table->string('last_processing_error_code', 64)->nullable();
            $table->string('last_processing_error_message', 255)->nullable();

            $table->index(['is_active', 'next_fetch_at'], 'mailbox_fetch_due');
            $table->index('fetch_queued_at');
            $table->index('fetch_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropIndex('mailbox_fetch_due');
            $table->dropIndex(['fetch_queued_at']);
            $table->dropIndex(['fetch_started_at']);
            $table->dropColumn([
                'last_fetch_attempted_at',
                'last_fetch_succeeded_at',
                'provider_cursor',
                'consecutive_fetch_failures',
                'last_fetch_error_category',
                'last_fetch_error_code',
                'last_fetch_error_message',
                'next_fetch_at',
                'fetch_queued_at',
                'fetch_started_at',
                'pending_inbound_count',
                'consecutive_processing_failures',
                'last_processing_succeeded_at',
                'last_processing_failed_at',
                'last_processing_error_code',
                'last_processing_error_message',
            ]);
        });
    }
};
