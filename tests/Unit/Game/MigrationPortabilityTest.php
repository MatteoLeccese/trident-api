<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The migrations have to work on PostgreSQL (the application) and on in-memory
 * SQLite (the suite), and right now this machine has no PDO driver at all with
 * which to run them.
 *
 * So instead of trusting them, they are **compiled to SQL against both grammars**
 * without connecting to anything. That catches exactly the class of failure that
 * would only show up in the first production migration: constructs that SQLite
 * accepts and Postgres rejects.
 */
final class MigrationPortabilityTest extends TestCase
{
    private const PLAY_STATE = '2026_09_17_100000_add_play_state_to_games_table.php';

    /**
     * A builder that compiles instead of executing.
     */
    private function capturingBuilder(Connection $connection): Builder
    {
        return new class($connection) extends Builder
        {
            /** @var list<string> */
            public array $sql = [];

            protected function build(Blueprint $blueprint): void
            {
                foreach ($blueprint->toSql() as $statement) {
                    $this->sql[] = $statement;
                }
            }
        };
    }

    /**
     * @return list<string>
     */
    private function compile(string $migration, Connection $connection): array
    {
        $builder = $this->capturingBuilder($connection);

        /** @var object $instance */
        $instance = require __DIR__.'/../../../database/migrations/'.$migration;

        // The Schema facade resolves 'db.schema' from the container; it is swapped
        // for the builder that only compiles.
        Schema::swap($builder);

        $instance->up();

        return $builder->sql;
    }

    private function postgres(): Connection
    {
        $connection = new PostgresConnection(static fn () => null, 'trident', '', ['driver' => 'pgsql']);
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }

    private function sqlite(): Connection
    {
        // The SQLite grammar asks the server for its version before compiling an
        // ALTER TABLE — below 3.35 it cannot drop a column — so a connection with
        // no PDO at all crashes on the first migration that alters a table rather
        // than creating one. The stub answers that one question and nothing else:
        // 3.45 is what the image ships, and it is the version the compiled SQL is
        // being judged against.
        $connection = new SQLiteConnection(
            fn (): object => $this->serverAnnouncing('3.45.0'),
            ':memory:',
            '',
            ['driver' => 'sqlite'],
        );
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }

    /**
     * A stand-in for PDO that answers the only question a schema grammar asks of
     * it, so that nothing here opens a connection to anything.
     */
    private function serverAnnouncing(string $version): object
    {
        return new class($version)
        {
            public function __construct(private readonly string $version) {}

            public function getAttribute(int $attribute): string
            {
                return $this->version;
            }
        };
    }

    /**
     * @return list<array{string}>
     */
    public static function migrations(): array
    {
        return [
            'games' => ['2026_09_16_100000_create_games_table.php'],
            'game_seats' => ['2026_09_16_100100_create_game_seats_table.php'],
            'game_moves' => ['2026_09_16_100200_create_game_moves_table.php'],
            'games play state' => ['2026_09_17_100000_add_play_state_to_games_table.php'],
        ];
    }

    #[DataProvider('migrations')]
    public function test_it_compiles_on_postgres(string $migration): void
    {
        $sql = $this->compile($migration, $this->postgres());

        $this->assertNotEmpty($sql, "{$migration} produced no SQL on Postgres.");
    }

    #[DataProvider('migrations')]
    public function test_it_compiles_on_sqlite(string $migration): void
    {
        $sql = $this->compile($migration, $this->sqlite());

        $this->assertNotEmpty($sql, "{$migration} produced no SQL on SQLite.");
    }

    public function test_the_play_state_columns_are_jsonb_on_postgres_and_varchar_where_they_are_closed_sets(): void
    {
        // jsonb for the opaque blobs — native in Postgres, text in SQLite — and
        // VARCHAR for everything the application validates. Never a native enum:
        // it is what keeps the two engines interchangeable.
        $sql = implode(' ', $this->compile(self::PLAY_STATE, $this->postgres()));

        foreach (['pool', 'rule_state', 'room_config', 'stage_visits', 'pending_choice'] as $blob) {
            $this->assertStringContainsString("\"{$blob}\" jsonb", $sql);
        }

        $this->assertStringContainsString('"rule_set_id" varchar(64)', $sql);
        $this->assertStringContainsString('"shuffle_seed" varchar(64)', $sql);
        $this->assertStringContainsString('"stage" varchar(32)', $sql);
        $this->assertStringContainsString('"finish_reason" varchar(32)', $sql);
        $this->assertStringNotContainsString('check (', strtolower($sql));
    }

    public function test_the_two_map_columns_default_to_an_empty_object_and_never_to_an_empty_list(): void
    {
        // `{}` and `[]` are different values, and Postgres and SQLite render them
        // differently. Both columns hold a map of keys.
        foreach ([$this->postgres(), $this->sqlite()] as $connection) {
            $sql = implode(' ', $this->compile(self::PLAY_STATE, $connection));

            $this->assertStringContainsString("default '{}'", $sql);
            $this->assertStringNotContainsString("default '[]'", $sql);
        }
    }

    public function test_r1_no_column_of_the_schema_names_a_rule(): void
    {
        // The declared success criterion of documentation/conventions/rule-set-seam.md
        // is that the phase which introduces a second ruleset adds no migration at
        // all. A column that spells out a challenge, a role, a stage or a drink is
        // exactly how that criterion dies, so the guard runs over EVERY migration
        // and not only over the newest one.
        //
        // `roles` is deliberately absent from the needles: it is the opaque bag a
        // ruleset writes an opaque string into, which is what makes TR-29 hold
        // without a `trident_seat` column.
        $needles = [
            'trident',
            'challenge',
            'election',
            'main_stage',
            'drink',
            'sip',
            'score',
            'points',
            'pip',
            'domino',
            'face',
        ];

        foreach (self::migrations() as [$migration]) {
            $sql = strtolower(implode(' ', $this->compile($migration, $this->postgres())));

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $sql, "{$migration} names '{$needle}'.");
            }
        }
    }

    public function test_the_play_state_migration_adds_no_index_and_no_constraint(): void
    {
        // Nothing queries a game by its stage, its ruleset or its seed, and an
        // index on a column the schema must stay ignorant of is the first step
        // towards querying it. A foreign key from `current_seat` to a seat row
        // would be worse still: the roster is written as a SET, so the row a key
        // pointed at is deleted and reinserted on every single save.
        foreach ([$this->postgres(), $this->sqlite()] as $connection) {
            $sql = strtolower(implode(' ', $this->compile(self::PLAY_STATE, $connection)));

            $this->assertStringNotContainsString('create index', $sql);
            $this->assertStringNotContainsString('unique', $sql);
            $this->assertStringNotContainsString('foreign key', $sql);
            $this->assertStringNotContainsString('references', $sql);
        }
    }

    public function test_the_schema_is_exactly_four_migrations(): void
    {
        // The whole product is four migrations, and a fifth is a claim that the
        // schema had to learn something new. It is allowed to happen; it is not
        // allowed to happen quietly. An empty scan is a failure, so the count is
        // asserted against the directory itself.
        $files = glob(__DIR__.'/../../../database/migrations/*.php') ?: [];

        $this->assertCount(4, $files, 'A migration was added. If the schema had to grow, say so here on purpose.');
        $this->assertSame(
            array_column(self::migrations(), 0),
            array_map('basename', $files),
        );
    }

    public function test_the_token_hash_is_varchar_and_never_blank_padded_char(): void
    {
        // `char` in Postgres is bpchar: it ignores trailing spaces when comparing,
        // which is a bad neighbour for a hash_equals. And SQLite would store it as
        // varchar anyway, so the engines would not even match.
        $sql = implode(' ', $this->compile('2026_09_16_100000_create_games_table.php', $this->postgres()));

        $this->assertStringContainsString('"controller_token_hash" varchar(64)', $sql);
        $this->assertStringNotContainsString('"controller_token_hash" char(', $sql);
    }

    public function test_the_unique_constraints_carry_no_predicate_and_no_expression(): void
    {
        // A partial or functional index compiles on SQLite and blows up on Postgres,
        // where a UNIQUE constraint only accepts column names. It would pass the whole
        // suite and break the first production migration.
        foreach (self::migrations() as [$migration]) {
            foreach ([$this->postgres(), $this->sqlite()] as $connection) {
                $sql = implode(' ', $this->compile($migration, $connection));

                $this->assertStringNotContainsString(' where ', strtolower($sql));
                $this->assertStringNotContainsString('lower(', strtolower($sql));
                $this->assertStringNotContainsString('nulls not distinct', strtolower($sql));
            }
        }
    }

    public function test_the_move_log_is_unique_by_game_and_sequence(): void
    {
        // A sequence number belongs to exactly one entry, so two writes that
        // derived the same next sequence cannot both commit. `lockForUpdate()` is
        // a no-op on SQLite, so no lock can stand in for this index.
        $sql = implode(' ', $this->compile('2026_09_16_100200_create_game_moves_table.php', $this->postgres()));

        $this->assertStringContainsString('game_moves_game_id_seq_unique', $sql);
    }

    public function test_tr_55_the_draw_history_lives_in_game_moves_and_dies_with_its_game(): void
    {
        // The history is a record and not a score: the columns are the move and
        // its payload, there is nothing to carry a total, and the rows go when
        // the game goes, so nothing survives into another game.
        $sql = implode(' ', $this->compile('2026_09_16_100200_create_game_moves_table.php', $this->postgres()));

        $this->assertStringContainsString('on delete cascade', strtolower($sql));
        $this->assertStringContainsString('"game_id" uuid not null', strtolower($sql));

        foreach (['score', 'points', 'total', 'count', 'drinks'] as $tally) {
            $this->assertStringNotContainsString("\"{$tally}\"", strtolower($sql));
        }
    }

    public function test_the_seat_json_columns_are_jsonb_on_postgres(): void
    {
        // The repository encodes both columns by hand, because a bulk insert goes
        // through the query builder and applies no cast. `jsonb` parses what it is
        // given and renders it again on the way out, so nothing may depend on the
        // stored text being byte for byte what was encoded.
        $sql = implode(' ', $this->compile('2026_09_16_100100_create_game_seats_table.php', $this->postgres()));

        $this->assertStringContainsString('"roles" jsonb', $sql);
        $this->assertStringContainsString('"private_state" jsonb', $sql);
    }

    public function test_seats_are_unique_by_number_and_by_folded_nickname(): void
    {
        $sql = implode(' ', $this->compile('2026_09_16_100100_create_game_seats_table.php', $this->postgres()));

        $this->assertStringContainsString('game_seats_game_id_seat_number_unique', $sql);
        $this->assertStringContainsString('game_seats_game_id_nickname_key_unique', $sql);
    }
}
