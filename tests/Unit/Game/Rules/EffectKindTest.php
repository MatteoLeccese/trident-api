<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Src\Game\Domain\Rules\EffectKind;

final class EffectKindTest extends TestCase
{
    public function test_it_declares_exactly_three_kinds(): void
    {
        $this->assertSame(['announce', 'assign_role', 'challenge'], EffectKind::ALL);
    }

    public function test_it_recognises_its_own_values_and_nothing_else(): void
    {
        foreach (EffectKind::ALL as $kind) {
            $this->assertTrue(EffectKind::isValid($kind));
        }

        $this->assertFalse(EffectKind::isValid('assignRole'));
        $this->assertFalse(EffectKind::isValid(''));
    }

    public function test_there_is_no_drinking_kind(): void
    {
        // Drinking is the default content of a challenge and never the mechanism:
        // a kind for it would bake a theme into the one place whose declared
        // purpose is to know none. Nothing carries a quantity either (TR-54).
        $constants = (new ReflectionClass(EffectKind::class))->getConstants();

        unset($constants['ALL']);

        $this->assertSame(['ANNOUNCE', 'ASSIGN_ROLE', 'CHALLENGE'], array_keys($constants));
    }
}
