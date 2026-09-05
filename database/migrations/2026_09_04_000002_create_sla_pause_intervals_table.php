<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_pause_intervals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sla_timer_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();

            $table->foreign('sla_timer_id')->references('id')->on('sla_timers')->cascadeOnDelete();
            $table->index(['sla_timer_id', 'started_at']);
            $table->index(['sla_timer_id', 'ended_at']);
        });

        $now = now();
        DB::table('sla_timers')
            ->whereNotNull('paused_at')
            ->orderBy('id')
            ->get(['id', 'paused_at'])
            ->each(function (object $timer) use ($now): void {
                DB::table('sla_pause_intervals')->insert([
                    'id' => (string) Str::uuid(),
                    'sla_timer_id' => $timer->id,
                    'started_at' => $timer->paused_at,
                    'ended_at' => null,
                    'duration_seconds' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_pause_intervals');
    }
};
