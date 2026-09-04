<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 32);
            $table->string('event_type', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('summary');
            $table->string('correlation_id');
            $table->boolean('customer_visible')->default(false);
            $table->timestamp('created_at', precision: 6);

            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ticket_id', 'created_at'], 'ticket_activities_timeline_index');
            $table->index(
                ['ticket_id', 'customer_visible', 'created_at'],
                'ticket_activities_customer_timeline_index',
            );
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
