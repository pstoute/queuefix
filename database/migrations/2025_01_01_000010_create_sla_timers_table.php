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
        Schema::create('sla_timers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('sla_policy_id');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->integer('total_paused_seconds')->default(0);
            $table->boolean('first_response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
            $table->foreign('sla_policy_id')->references('id')->on('sla_policies')->onDelete('cascade');

            $table->index('ticket_id');
            $table->index('sla_policy_id');
            $table->index('first_response_due_at');
            $table->index('resolution_due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_timers');
    }
};
