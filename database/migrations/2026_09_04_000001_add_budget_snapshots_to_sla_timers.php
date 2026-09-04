<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_timers', function (Blueprint $table) {
            $table->timestamp('first_response_started_at')->nullable()->after('sla_policy_id');
            $table->unsignedBigInteger('first_response_budget_seconds')->nullable()->after('first_response_started_at');
            $table->timestamp('resolution_started_at')->nullable()->after('first_responded_at');
            $table->unsignedBigInteger('resolution_budget_seconds')->nullable()->after('resolution_started_at');
        });

        DB::table('sla_timers')
            ->join('sla_policies', 'sla_timers.sla_policy_id', '=', 'sla_policies.id')
            ->select([
                'sla_timers.id',
                'sla_timers.created_at',
                'sla_timers.first_response_due_at',
                'sla_timers.resolution_due_at',
                'sla_timers.total_paused_seconds',
                'sla_policies.first_response_hours',
                'sla_policies.resolution_hours',
            ])
            ->orderBy('sla_timers.id')
            ->each(function (object $timer): void {
                $startedAt = Carbon::parse($timer->created_at);
                $pausedSeconds = max(0, (int) $timer->total_paused_seconds);
                $firstResponseBudgetSeconds = $timer->first_response_due_at
                    ? max(0, Carbon::parse($timer->first_response_due_at)->getTimestamp() - $startedAt->getTimestamp() - $pausedSeconds)
                    : max(0, (int) round((float) $timer->first_response_hours * 3600));
                $resolutionBudgetSeconds = $timer->resolution_due_at
                    ? max(0, Carbon::parse($timer->resolution_due_at)->getTimestamp() - $startedAt->getTimestamp() - $pausedSeconds)
                    : max(0, (int) round((float) $timer->resolution_hours * 3600));

                DB::table('sla_timers')->where('id', $timer->id)->update([
                    'first_response_started_at' => $startedAt,
                    'first_response_budget_seconds' => $firstResponseBudgetSeconds,
                    'resolution_started_at' => $startedAt,
                    'resolution_budget_seconds' => $resolutionBudgetSeconds,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('sla_timers', function (Blueprint $table) {
            $table->dropColumn([
                'first_response_started_at',
                'first_response_budget_seconds',
                'resolution_started_at',
                'resolution_budget_seconds',
            ]);
        });
    }
};
