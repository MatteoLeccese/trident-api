<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Exceptions;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\BusinessException;
use Src\Shared\Domain\Exceptions\DomainException;

final class BusinessExceptionTest extends TestCase
{
    public function test_it_carries_code_message_status_and_data(): void
    {
        $exception = new BusinessException('not_your_turn', 'No es tu turno.', 422, ['seat' => 3]);

        $this->assertSame('not_your_turn', $exception->errorCode());
        $this->assertSame('No es tu turno.', $exception->getMessage());
        $this->assertSame(422, $exception->status());
        $this->assertSame(['seat' => 3], $exception->data());
    }

    public function test_it_defaults_to_422_with_no_data(): void
    {
        $exception = new BusinessException('tile_already_taken', 'Esa ficha ya está cogida.');

        $this->assertSame(422, $exception->status());
        $this->assertNull($exception->data());
    }

    public function test_it_is_a_domain_exception(): void
    {
        // What decides it is NOT reported to the log: business failures are expected.
        $this->assertInstanceOf(DomainException::class, new BusinessException('x_y', 'msg'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCodes(): array
    {
        return [
            'vacío' => [''],
            'camelCase' => ['notYourTurn'],
            'kebab-case' => ['not-your-turn'],
            'con espacios' => ['not your turn'],
            'empieza por número' => ['4_oclock'],
            'mayúsculas' => ['NOT_YOUR_TURN'],
            'con salto de línea al final' => ["not_your_turn\n"],
        ];
    }

    /**
     * The frontend branches on the code, never on the message. A code with a
     * different shape breaks the `lib/error-codes.ts` map without anyone noticing.
     */
    #[DataProvider('invalidCodes')]
    public function test_it_rejects_codes_that_are_not_snake_case(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BusinessException($invalid, 'mensaje');
    }
}
