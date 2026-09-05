<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canned_responses', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('body');
            $table->string('visibility', 24)->default('all_agents')->after('is_active');
            $table->index(['is_active', 'visibility'], 'canned_response_availability');
        });
    }

    public function down(): void
    {
        Schema::table('canned_responses', function (Blueprint $table) {
            $table->dropIndex('canned_response_availability');
            $table->dropColumn(['is_active', 'visibility']);
        });
    }
};
