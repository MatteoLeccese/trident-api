<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_moves', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained('games')->cascadeOnDelete();

            $table->unsignedInteger('seq');
            $table->unsignedSmallInteger('actor_seat')->nullable();
            $table->string('kind', 32);
            $table->jsonb('payload')->default('{}');

            // Idempotency ledger: a replay returns the existing state instead of
            // advancing the turn twice.
            $table->uuid('request_id')->nullable()->unique();

            $table->timestampTz('created_at');

            // A sequence number belongs to exactly one entry: two writes that
            // derived the same next sequence cannot both commit. It is not an
            // expected-version guard — the writer does not carry the version it
            // read into the write — and it is not a lock either: `lockForUpdate()`
            // is a no-op in SQLite, so the suite cannot exercise one.
            $table->unique(['game_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_moves');
    }
};
