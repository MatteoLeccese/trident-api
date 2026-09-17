<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\JoinCode;

final class JoinCodeTest extends TestCase
{
    public function test_it_generates_six_characters(): void
    {
        $this->assertSame(6, strlen(JoinCode::generate()->value()));
    }

    public function test_it_never_generates_an_ambiguous_character(): void
    {
        // It is typed on a television remote, looking at it from the sofa.
        // I/1, L/1, O/0 and U are the classic confusions: Crockford removes them.
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{6}\z/', JoinCode::generate()->value());
        }
    }

    public function test_it_does_not_repeat_itself(): void
    {
        $codes = [];

        for ($i = 0; $i < 100; $i++) {
            $codes[] = JoinCode::generate()->value();
        }

        $this->assertCount(100, array_unique($codes));
    }

    public function test_it_forgives_how_a_person_types_it(): void
    {
        // Someone reads "K7QP3M" on the TV and types "k7qp3m". It works.
        $this->assertSame('K7QP3M', JoinCode::fromString('k7qp3m')->value());
        $this->assertSame('K7QP3M', JoinCode::fromString(' K7QP3M ')->value());
        $this->assertSame('K7QP3M', JoinCode::fromString('K7QP-3M')->value());
        $this->assertSame('K7QP3M', JoinCode::fromString("K7QP3M\n")->value());
        // Someone types it in two groups, the way it reads on the screen.
        $this->assertSame('K7QP3M', JoinCode::fromString('K7Q P3M')->value());
    }

    public function test_it_maps_the_characters_people_confuse(): void
    {
        // They see a 0 and type an O. They see a 1 and type an I or an L.
        $this->assertSame('01234K', JoinCode::fromString('O1234K')->value());
        $this->assertSame('01234K', JoinCode::fromString('0I234K')->value());
        $this->assertSame('01234K', JoinCode::fromString('0L234K')->value());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCodes(): array
    {
        return [
            'empty' => [''],
            'too short' => ['K7QP3'],
            'too long' => ['K7QP3MX'],
            'with symbols' => ['K7QP3!'],
            'with a null byte' => ["K7QP3\x00M"],
            'with a control character' => ["K7QP3\x07M"],
            'with the excluded U' => ['K7QP3U'],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_it_rejects_what_is_not_a_code(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        JoinCode::fromString($invalid);
    }
}
