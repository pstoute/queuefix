<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticket_number')->unique();
            $table->string('subject');
            $table->enum('status', ['open', 'pending', 'on_hold', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->uuid('customer_id');
            $table->uuid('assigned_to')->nullable();
            $table->uuid('mailbox_id')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->onDelete('set null');

            $table->index('ticket_number');
            $table->index('status');
            $table->index('priority');
            $table->index('customer_id');
            $table->index('assigned_to');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
