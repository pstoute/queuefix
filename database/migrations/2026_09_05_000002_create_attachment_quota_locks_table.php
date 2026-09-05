<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_quota_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('attachment_quota_locks')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_quota_locks');
    }
};
