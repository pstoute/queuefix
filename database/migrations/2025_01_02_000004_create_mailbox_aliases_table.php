<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->foreignUuid('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_aliases');
    }
};
