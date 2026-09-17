<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\ControllerToken;

/**
 * The write credential. Whoever holds it runs the game.
 * See documentation/conventions/credential-model.md.
 */
final class ControllerTokenTest extends TestCase
{
    public function test_it_generates_enough_entropy_to_be_unguessable(): void
    {
        $token = ControllerToken::generate();

        // 32 bytes in base64url: 43 characters with no padding.
        $this->assertSame(43, strlen($token->value()));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token->value());
    }

    public function test_it_does_not_repeat(): void
    {
        $tokens = [];

        for ($i = 0; $i < 100; $i++) {
            $tokens[] = ControllerToken::generate()->value();
        }

        $this->assertCount(100, array_unique($tokens));
    }

    public function test_only_its_hash_is_ever_stored(): void
    {
        $token = ControllerToken::generate();

        $this->assertSame(64, strlen($token->hash()));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token->hash());
        $this->assertNotSame($token->value(), $token->hash());
    }

    public function test_the_same_token_always_hashes_the_same(): void
    {
        $raw = ControllerToken::generate()->value();

        $this->assertSame(
            ControllerToken::fromString($raw)->hash(),
            ControllerToken::fromString($raw)->hash(),
        );
    }

    public function test_it_verifies_against_a_stored_hash(): void
    {
        $token = ControllerToken::generate();
        $stored = $token->hash();

        $this->assertTrue($token->matchesHash($stored));
        $this->assertFalse(ControllerToken::generate()->matchesHash($stored));
    }

    public function test_verification_rejects_a_malformed_stored_hash(): void
    {
        $token = ControllerToken::generate();

        $this->assertFalse($token->matchesHash(''));
        $this->assertFalse($token->matchesHash('not-a-hash'));
    }

    public function test_it_never_leaks_through_string_conversion(): void
    {
        // A token in a log, in an error message or in a var_dump is a stolen
        // token. These are the three ways it escapes by accident.
        $token = ControllerToken::generate();

        $this->assertStringNotContainsString($token->value(), print_r($token, true));
        $this->assertStringNotContainsString($token->value(), var_export($token, true));
        $this->assertStringNotContainsString($token->value(), json_encode(['t' => $token]) ?: '');
    }

    public function test_it_cannot_be_serialised_into_a_session_or_a_cache(): void
    {
        $this->expectException(\Exception::class);

        serialize(ControllerToken::generate());
    }

    public function test_it_is_not_stringable_by_accident(): void
    {
        // Without this, `"token: {$token}"` anywhere leaks it.
        $this->assertFalse(method_exists(ControllerToken::class, '__toString'));
    }

    public function test_it_rejects_a_value_that_is_not_a_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ControllerToken::fromString('short');
    }
}
