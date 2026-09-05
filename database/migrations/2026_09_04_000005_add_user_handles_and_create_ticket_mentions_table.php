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
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 48)->nullable()->after('name');
        });

        $usedHandles = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $base = $this->normalizeHandle($user->name);
            $candidate = $base;
            $suffix = 2;

            while (isset($usedHandles[$candidate])) {
                $candidate = Str::limit($base, 43, '').'-'.$suffix;
                $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['handle' => $candidate]);
            $usedHandles[$candidate] = true;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 48)->nullable(false)->change();
            $table->unique('handle');
        });

        Schema::create('ticket_mentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('message_id');
            $table->uuid('mentioned_user_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'mentioned_user_id']);
            $table->index(['mentioned_user_id', 'removed_at']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('mentioned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_mentions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn('handle');
        });
    }

    private function normalizeHandle(string $name): string
    {
        $handle = Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-_')
            ->limit(43, '')
            ->toString();

        return $handle !== '' ? $handle : 'user';
    }
};
