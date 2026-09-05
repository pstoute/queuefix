<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_email_receipts', function (Blueprint $table) {
            $table->string('disposition', 16)->default('processed');
            $table->string('rejection_reason', 64)->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->mediumText('body_text')->change();
            $table->mediumText('body_html')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body_text')->change();
            $table->text('body_html')->nullable()->change();
        });

        Schema::table('inbound_email_receipts', function (Blueprint $table) {
            $table->dropColumn(['disposition', 'rejection_reason']);
        });
    }
};
