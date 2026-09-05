<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('ticket_status_id');
            $table->timestamp('priority_changed_at')->nullable()->after('priority');
            $table->index('status_changed_at');
            $table->index('priority_changed_at');
        });

        Schema::create('escalation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('trigger', 40);
            $table->json('trigger_config');
            $table->json('filters');
            $table->json('actions');
            $table->boolean('include_closed')->default(false);
            $table->boolean('include_archived')->default(false);
            $table->boolean('is_active')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamp('last_previewed_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'trigger']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('escalation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('escalation_rule_id');
            $table->uuid('ticket_id');
            $table->string('idempotency_key', 64)->unique();
            $table->string('trigger_window');
            $table->json('trigger_context');
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('actor', 32)->default('system');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['escalation_rule_id', 'ticket_id', 'status'], 'escalation_log_lookup');
            $table->foreign('escalation_rule_id')->references('id')->on('escalation_rules')->restrictOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();
        });

        Schema::create('escalation_action_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('escalation_log_id');
            $table->uuid('escalation_rule_id');
            $table->uuid('ticket_id');
            $table->unsignedInteger('attempt');
            $table->unsignedSmallInteger('action_order');
            $table->string('action_type', 32);
            $table->string('status', 20);
            $table->string('actor', 32)->default('system');
            $table->json('before_context')->nullable();
            $table->json('after_context')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['escalation_log_id', 'attempt', 'action_order'], 'escalation_action_attempt_unique');
            $table->index(['escalation_rule_id', 'ticket_id'], 'escalation_action_lookup');
            $table->foreign('escalation_log_id')->references('id')->on('escalation_logs')->cascadeOnDelete();
            $table->foreign('escalation_rule_id')->references('id')->on('escalation_rules')->restrictOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_action_logs');
        Schema::dropIfExists('escalation_logs');
        Schema::dropIfExists('escalation_rules');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status_changed_at']);
            $table->dropIndex(['priority_changed_at']);
            $table->dropColumn(['status_changed_at', 'priority_changed_at']);
        });
    }
};
