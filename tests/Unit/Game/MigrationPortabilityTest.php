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
        $connection = new SQLiteConnection(static fn () => null, ':memory:', '', ['driver' => 'sqlite']);
        $connection->useDefaultSchemaGrammar();

        return $connection;
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
