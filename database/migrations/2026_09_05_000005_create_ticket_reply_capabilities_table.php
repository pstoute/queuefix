<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('reply_address_template')->nullable()->after('email');
        });

        Schema::create('ticket_reply_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('origin_ticket_id')->unique();
            $table->uuid('mailbox_id');
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('origin_ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->index(['mailbox_id', 'token_hash', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reply_capabilities');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('reply_address_template');
        });
    }
};
