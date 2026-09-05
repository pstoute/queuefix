<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_support_manager')->default(false)->after('role')->index();
        });

        Schema::create('ticket_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('staff_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_ratings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_support_manager']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_support_manager');
        });
    }
};
