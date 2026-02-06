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
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent']);
            $table->decimal('first_response_hours', 8, 2);
            $table->decimal('resolution_hours', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('priority');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
