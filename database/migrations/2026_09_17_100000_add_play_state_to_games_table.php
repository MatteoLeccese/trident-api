<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            // The ruleset a game is pinned to when play begins. The resolver reads
            // THIS and never a configuration key, so changing the default ruleset
            // cannot change the meaning of a game already on a table.
            $table->string('rule_set_id', 64)->nullable();

            // The server's only secret. It is stored raw and not hashed, because a
            // shuffle that cannot be recomputed is a pool that cannot be read back;
            // it leaves the server in no response body, no error body and no
            // broadcast. 64 and not 43 so that the column does not encode the
            // current encoding's length.
            $table->string('shuffle_seed', 64)->nullable();

            // An OPAQUE identifier owned by the ruleset. The framework stores it,
            // projects it and indexes nothing by it; no column, constraint or check
            // in this schema knows which stages exist or what any of them means.
            // VARCHAR validated in the application, never a native ENUM.
            $table->string('stage', 32)->nullable();

            // The one seat that acts. A bare number, exactly like
            // `game_moves.actor_seat`, and never a foreign key to a seat row: the
            // roster is written as a set, so the row a key pointed at is deleted
            // and reinserted on every save.
            $table->unsignedSmallInteger('current_seat')->nullable();

            // The stage's materialised pool:
            // `{"tiles": [...], "taken": [{"position": 1, "seat": 2}, ...]}`, in
            // pool order, holding EVERY face including the ones the projection
            // hides. Hiding is a property of the projection and never of storage.
            // `taken` is a list of objects and never a map keyed by position,
            // because an object keyed by an integer comes back with string keys.
            // NULL while no stage is in play.
            $table->jsonb('pool')->nullable();

            // Whatever the ruleset keeps between turns, carried and never read by
            // the framework. It always holds `_v`, and a ruleset that cannot read
            // a blob its own older version wrote ends the game rather than
            // misreading it.
            $table->jsonb('rule_state')->nullable();

            // The table's settings: a FLAT map of dotted keys to scalars, written
            // by people and read by the ruleset that declared them. It carries no
            // version and ends no game. One opaque blob and not a typed column per
            // setting, which is how a new setting stays a line of
            // `roomConfigSpec()` instead of an ALTER TABLE.
            $table->jsonb('room_config')->default('{}');

            // How many times each stage has been entered, keyed by stage id. It is
            // what gives a stage entered twice an independently shuffled pool, and
            // it cannot be derived from the move log: a stage entered and left
            // without a draw leaves no trace there.
            $table->jsonb('stage_visits')->default('{}');

            // The question a rule parked the game on, and the closed list of
            // answers the seat it names may give. NULL unless the status says the
            // game is waiting for one.
            $table->jsonb('pending_choice')->nullable();

            // Why a finished game finished, as the framework's own closed
            // vocabulary names it. An opaque string to storage: nothing queries it
            // and no constraint enumerates it.
            $table->string('finish_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn([
                'rule_set_id',
                'shuffle_seed',
                'stage',
                'current_seat',
                'pool',
                'rule_state',
                'room_config',
                'stage_visits',
                'pending_choice',
                'finish_reason',
            ]);
        });
    }
};
