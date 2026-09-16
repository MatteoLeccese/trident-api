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

            // THIS index is the concurrency control, not a belt over a lock:
            // `lockForUpdate()` is literally a no-op in SQLite, so the suite cannot
            // exercise a lock. Two writes with the same expected version cannot both
            // commit.
            $table->unique(['game_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_moves');
    }
};
