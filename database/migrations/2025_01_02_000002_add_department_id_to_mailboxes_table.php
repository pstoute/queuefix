<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->dropColumn('department');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->string('department')->nullable();
        });
    }
};
