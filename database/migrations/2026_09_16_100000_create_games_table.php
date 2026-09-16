<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            // UUID v4 assigned by the domain before saving (golden rule 10).
            // It is also the read-only credential a television comes in with, so it
            // has to be unguessable: never a counter.
            $table->uuid('id')->primary();

            // Nullable and with a PLAIN unique, not a partial index: Laravel cannot
            // express a predicate in a portable way. The code is freed by setting
            // this to NULL when the game ends, and a unique treats NULLs as distinct
            // in Postgres and in SQLite alike.
            $table->string('join_code', 6)->nullable()->unique();

            // string(64) and NOT char(64): in Postgres, `char` is bpchar and pads
            // with spaces, and ignores the trailing ones when comparing — a bad
            // neighbour for a hash_equals. Besides, SQLite ignores `char` and stores
            // varchar, so the two engines would not even agree on what is stored.
            $table->string('controller_token_hash', 64);

            // VARCHAR validated in the application, never a native ENUM
            // (enum-persistence convention: keeps PG and SQLite interchangeable).
            $table->string('status', 16)->index();

            $table->unsignedBigInteger('version')->default(1);

            // Sliding window: refreshed on every write. Expiry is a column governed
            // by a command, never a key TTL.
            $table->timestampTz('last_activity_at')->index();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
