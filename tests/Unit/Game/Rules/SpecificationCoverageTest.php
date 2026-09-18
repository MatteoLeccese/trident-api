<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceInspector;

/**
 * The audit documentation/conventions/trident-rules.md asks for, performed by a
 * test instead of by eye: every numbered assertion has a test named with its
 * number, and every test that claims a number claims one the document defines.
 *
 * Most of those tests are in `TridentRuleSetTest`, which is where the document
 * says the strategy lives. Four assertions are claims of an object other than
 * the ruleset — a pool, a seed, a name, a migration — and carry their number in
 * that object's own test; this file is what keeps that from becoming a gap
 * nobody can see.
 *
 * A number may carry a letter. Both patterns read one, and the count is the
 * whole document: a guard that silently skips an assertion is the gap it was
 * written to close.
 *
 * Golden rule of the structural guards: an empty scan is a failure, not a pass.
 */
final class SpecificationCoverageTest extends TestCase
{
    private const RULES = 'documentation/conventions/trident-rules.md';

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * @return list<string>
     */
    private function documentedNumbers(): array
    {
        $document = (string) file_get_contents(self::root().'/'.self::RULES);

        // A lettered sub-assertion — `TR-48b` — is an assertion like any other,
        // and a pattern that cannot match one leaves it outside both lists, where
        // the two still coincide and its test can be deleted unnoticed.
        preg_match_all('/^\*\*TR-(\d{2}[a-z]?)\.\*\*/m', $document, $matches);

        $numbers = array_values(array_unique($matches[1]));
        sort($numbers);

        return $numbers;
    }

    /**
     * @return list<string>
     */
    private function numbersWithATest(): array
    {
        $numbers = [];

        foreach (SourceInspector::phpFilesIn(self::root().'/tests') as $file) {
            preg_match_all('/function test_tr_(\d{2}[a-z]?)_/', (string) file_get_contents($file), $matches);

            $numbers = [...$numbers, ...$matches[1]];
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return $numbers;
    }

    public function test_the_specification_is_read_at_all(): void
    {
        $this->assertFileExists(self::root().'/'.self::RULES);
        $this->assertCount(56, $this->documentedNumbers());
    }

    public function test_every_numbered_assertion_carries_a_test_named_with_its_number(): void
    {
        $this->assertSame(
            $this->documentedNumbers(),
            $this->numbersWithATest(),
            'The two lists of trident-rules.md must coincide: a number with no test, or a test with no number.',
        );
    }
}
