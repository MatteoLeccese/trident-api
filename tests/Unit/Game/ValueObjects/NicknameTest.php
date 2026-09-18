<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Nickname;

final class NicknameTest extends TestCase
{
    public function test_it_keeps_the_name_as_typed(): void
    {
        $this->assertSame('Ana', Nickname::fromString('Ana')->value());
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->assertSame('Ana', Nickname::fromString('  Ana  ')->value());
    }

    public function test_it_squishes_runs_of_inner_whitespace(): void
    {
        // Two names that look the same on a television must be the same.
        $this->assertSame('Ana Mari', Nickname::fromString('Ana    Mari')->value());
    }

    public function test_tr_15_a_name_is_between_two_and_twenty_four_characters(): void
    {
        // "Bo" and "Al" are real names. The old system's minimum of 4 rejected
        // them; see documentation/conventions/waived-golden-rules.md.
        $this->assertSame(2, Nickname::MIN_LENGTH);
        $this->assertSame(24, Nickname::MAX_LENGTH);
        $this->assertSame('Bo', Nickname::fromString('Bo')->value());
        $this->assertSame('Al', Nickname::fromString('Al')->value());
    }

    public function test_it_accepts_accents_and_emoji(): void
    {
        $this->assertSame('Íñigo', Nickname::fromString('Íñigo')->value());
        $this->assertSame('Ana 🎲', Nickname::fromString('Ana 🎲')->value());
    }

    public function test_length_is_counted_in_characters_not_bytes(): void
    {
        // 24 accents are 48 bytes: counting bytes would reject a valid name.
        $this->assertSame(24, mb_strlen(Nickname::fromString(str_repeat('á', 24))->value()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNicknames(): array
    {
        return [
            'empty' => [''],
            'only spaces' => ['   '],
            'a single letter' => ['A'],
            'too long' => [str_repeat('a', 25)],
            'with a line break' => ["Ana\nMari"],
            'with a tab' => ["Ana\tMari"],
        ];
    }

    #[DataProvider('invalidNicknames')]
    public function test_it_rejects_what_cannot_go_on_a_television(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        Nickname::fromString($invalid);
    }

    public function test_it_compares_case_insensitively(): void
    {
        // "ana" and "Ana" at the same table is guaranteed confusion.
        $this->assertTrue(Nickname::fromString('Ana')->equals(Nickname::fromString('ana')));
        $this->assertTrue(Nickname::fromString('ÍÑIGO')->equals(Nickname::fromString('íñigo')));
        $this->assertFalse(Nickname::fromString('Ana')->equals(Nickname::fromString('Bea')));
    }

    public function test_it_exposes_a_comparison_key_for_the_database(): void
    {
        // Uniqueness is enforced by Postgres too; it needs the same notion.
        $this->assertSame('ana', Nickname::fromString('Ana')->comparisonKey());
    }
}
