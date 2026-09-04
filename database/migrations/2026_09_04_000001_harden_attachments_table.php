<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('claimed_mime_type')->nullable()->after('mime_type');
            $table->char('sha256', 64)->nullable()->after('size');
            $table->string('scan_status')->default('pending')->after('sha256');
            $table->string('rejection_reason')->nullable()->after('scan_status');

            $table->index('sha256');
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        DB::table('attachments')->whereNull('path')->delete();

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['sha256']);
            $table->dropIndex(['scan_status']);
            $table->dropColumn(['claimed_mime_type', 'sha256', 'scan_status', 'rejection_reason']);
            $table->string('path')->nullable(false)->change();
        });
    }
};
