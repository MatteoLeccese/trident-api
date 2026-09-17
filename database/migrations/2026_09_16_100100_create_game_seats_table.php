<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_seats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->constrained('games')->cascadeOnDelete();

            // 1..N. A single indexing convention across the whole system.
            $table->unsignedSmallInteger('seat_number');

            $table->string('nickname', 24);

            // Its own column and NOT a functional index over lower(nickname).
            // Two verified reasons: in Postgres a functional index compiles to a
            // UNIQUE constraint, which only accepts column names; and SQLite's
            // lower() folds ASCII only, so 'JOSÉ' and 'josé' would collide in
            // production but not in the tests.
            // 48 and not 24 because folding can expand: mb_strtolower('İ')
            // returns two characters.
            $table->string('nickname_key', 48);

            // jsonb: in Postgres it is native, in SQLite it falls back to text. Portable.
            $table->jsonb('roles')->default('[]');

            // Provisioned and unused. No rule hides information from anyone, so
            // nothing writes it; dropping it would cost a migration and keeping it
            // costs nothing.
            $table->jsonb('private_state')->default('{}');

            $table->unique(['game_id', 'seat_number']);
            $table->unique(['game_id', 'nickname_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_seats');
    }
};
