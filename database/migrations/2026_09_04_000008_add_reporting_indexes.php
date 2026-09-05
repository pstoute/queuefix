<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('created_at', 'tickets_created_at_report_index');
            $table->index('resolved_at', 'tickets_resolved_at_report_index');
            $table->index(['ticket_status_id', 'created_at'], 'tickets_open_report_index');
            $table->index(['department_id', 'created_at'], 'tickets_department_report_index');
            $table->index(['assigned_to', 'created_at'], 'tickets_assignee_report_index');
        });

        Schema::table('sla_timers', function (Blueprint $table) {
            $table->index('first_responded_at', 'sla_first_responded_report_index');
            $table->index('resolved_at', 'sla_resolved_report_index');
        });

        Schema::table('ticket_ratings', function (Blueprint $table) {
            $table->index(['submitted_at', 'rating'], 'ticket_ratings_report_index');
        });
    }

    public function down(): void
    {
        // InnoDB may replace the implicit department foreign-key index with
        // our composite index. Restore a supporting index before removing it.
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('tickets', 'tickets_department_id_index')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->index('department_id');
            });
        }

        Schema::table('ticket_ratings', function (Blueprint $table) {
            $table->dropIndex('ticket_ratings_report_index');
        });

        Schema::table('sla_timers', function (Blueprint $table) {
            $table->dropIndex('sla_first_responded_report_index');
            $table->dropIndex('sla_resolved_report_index');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_created_at_report_index');
            $table->dropIndex('tickets_resolved_at_report_index');
            $table->dropIndex('tickets_open_report_index');
            $table->dropIndex('tickets_department_report_index');
            $table->dropIndex('tickets_assignee_report_index');
        });
    }
};
