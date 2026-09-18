<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\SourceInspector;

/**
 * Structural guards. Purity that no test enforces decays within three months of
 * afternoon commits.
 *
 * Golden rule of this file: **an empty scan is a failure, not a pass.**
 * The previous version returned `[]` when a directory did not exist, so it stayed
 * green with every path misspelled — green because it looked at nothing.
 */
final class ArchitectureTest extends TestCase
{
    private const FORBIDDEN_IN_DOMAIN = [
        'Illuminate',
        'Eloquent',
        'Facade',
        'config(',
        'app(',
        'env(',
    ];

    /**
     * Facades by their default alias, which needs no import: Laravel's alias
     * loader resolves `\Log::` and `\Config::` in a file that imports nothing at
     * all. Matched on a word boundary, so `RoomConfig::` is not `Config::`.
     *
     * The list is a second net and not the guard: the guard is
     * `test_the_domain_only_names_classes_it_imports`, which refuses an
     * unimported name whether or not it is listed here.
     */
    private const FORBIDDEN_FACADES = [
        'App',
        'Arr',
        'Auth',
        'Broadcast',
        'Bus',
        'Cache',
        'Config',
        'DB',
        'Event',
        'File',
        'Http',
        'Log',
        'Queue',
        'Redis',
        'Route',
        'Schema',
        'Storage',
        'Str',
        'Validator',
    ];

    /**
     * Calls into the global random number generator.
     *
     * The needles are CALLS and not bare words: a docblock that explains why
     * `shuffle()` is not used here is prose, and `SeededShuffle` is a class name.
     */
    private const FORBIDDEN_RANDOMNESS = [
        'rand(',
        'mt_rand(',
        'mt_srand(',
        'srand(',
        'shuffle(',
        'array_rand(',
        'str_shuffle(',
    ];

    /** Contexts that must exist. Adding a new one here is part of creating it. */
    private const EXPECTED_CONTEXTS = ['Game', 'Realtime', 'Shared'];

    /**
     * The word this repository may not contain, assembled rather than written.
     *
     * Written out, this constant would be the first thing a scan of the tracked
     * tree found, and the guard would fail on itself.
     */
    private const DEVELOPMENT_STAGE_WORD = 'ph'.'ase';

    /** Where the tracked tree is scanned for it. Vendor and build output are not ours. */
    private const TRACKED_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'documentation', 'routes', 'src', 'tests'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $relative, bool $mustHaveFiles = true): array
    {
        $root = self::root().'/'.$relative;

        $this->assertDirectoryExists($root, "Expected to scan '{$relative}' but it does not exist.");

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        if ($mustHaveFiles) {
            $this->assertNotEmpty($files, "'{$relative}' contains no PHP: this test is not testing anything.");
        }

        return $files;
    }

    /** True only for a class PHP itself ships, never for one an alias resolves. */
    private function isPhpClass(string $name): bool
    {
        if (! class_exists($name) && ! interface_exists($name)) {
            return false;
        }

        return (new ReflectionClass($name))->isInternal();
    }

    public function test_the_expected_bounded_contexts_exist(): void
    {
        // If someone renames a context, the tests below would silently stop looking
        // at it. This prevents that.
        $found = array_map(
            static fn (string $path): string => basename(dirname($path)),
            glob(self::root().'/src/*/Domain', GLOB_ONLYDIR) ?: [],
        );

        sort($found);

        $this->assertSame(self::EXPECTED_CONTEXTS, $found);
    }

    public function test_the_domain_layer_never_imports_the_framework(): void
    {
        $offences = [];
        $scanned = 0;

        foreach (glob(self::root().'/src/*/Domain', GLOB_ONLYDIR) ?: [] as $domain) {
            $context = basename(dirname($domain));

            // A context with no domain files yet is legitimate, but the
            // directory has to exist: an empty scan is a failure, not a pass.
            foreach ($this->phpFilesIn("src/{$context}/Domain", mustHaveFiles: false) as $file) {
                $scanned++;
                $contents = SourceInspector::codeOf($file);

                foreach (self::FORBIDDEN_IN_DOMAIN as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offences[] = "src/{$context}/Domain/".basename($file)." contains '{$needle}'";
                    }
                }

                foreach (self::FORBIDDEN_FACADES as $facade) {
                    if (preg_match('/(?<![\w])'.$facade.'::/', $contents) === 1) {
                        $offences[] = "src/{$context}/Domain/".basename($file)." calls '{$facade}::'";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No domain file was scanned.');
        $this->assertSame([], $offences, 'The domain must be plain PHP.');
    }

    public function test_the_domain_only_names_classes_it_imports(): void
    {
        // The inverted form of the rule above, and the one that does not have to
        // be kept up to date: every import of a domain file resolves to PHP
        // itself or to a domain of this application, and every class named with
        // a `::` is one the file imported, one that sits beside it in the same
        // namespace, or a class of PHP. An unimported alias — the one shape a
        // needle list cannot enumerate — is therefore an offence by default.
        $offences = [];
        $scanned = 0;

        foreach (glob(self::root().'/src/*/Domain', GLOB_ONLYDIR) ?: [] as $domain) {
            $context = basename(dirname($domain));

            foreach ($this->phpFilesIn("src/{$context}/Domain", mustHaveFiles: false) as $file) {
                $scanned++;
                $code = SourceInspector::codeOf($file);
                $imported = [];

                preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $uses, PREG_SET_ORDER);

                foreach ($uses as $use) {
                    $fqn = ltrim($use[1], '\\');
                    $parts = explode('\\', $fqn);
                    $imported[$use[2] ?? end($parts)] = true;

                    if (preg_match('/\ASrc\\\\\w+\\\\Domain\\\\/', $fqn) !== 1 && ! $this->isPhpClass($fqn)) {
                        $offences[] = basename($file)." imports '{$fqn}'";
                    }
                }

                preg_match_all('/(?<![\w$\\\\])(\\\\?)([A-Z]\w*)::/', $code, $named, PREG_SET_ORDER);

                foreach ($named as [, $leading, $name]) {
                    $isNeighbour = $leading === '' && is_file(dirname($file)."/{$name}.php");

                    if (isset($imported[$name]) || $isNeighbour || $this->isPhpClass($name)) {
                        continue;
                    }

                    $offences[] = basename($file)." names '{$name}::' without importing it";
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No domain file was scanned.');
        $this->assertSame([], $offences, 'The domain names PHP and its own domain, and nothing else.');
    }

    public function test_nothing_draws_on_the_global_random_number_generator(): void
    {
        // A shuffle has to be reproducible from its seed, and global RNG state is
        // not a value: `mt_rand`'s internal state is recoverable from a handful of
        // outputs, and the revealed prefix of the board is exactly that handful.
        // Permutations go through SeededShuffle.
        $offences = [];

        foreach ($this->phpFilesIn('src') as $file) {
            $contents = SourceInspector::codeOf($file);

            foreach (self::FORBIDDEN_RANDOMNESS as $needle) {
                if (str_contains($contents, $needle)) {
                    $offences[] = basename($file)." calls '{$needle}'";
                }
            }
        }

        $this->assertSame([], $offences, 'Randomness is seeded and deterministic, or it is random_bytes().');
    }

    public function test_every_source_file_declares_strict_types(): void
    {
        $offences = [];
        $files = $this->phpFilesIn('src');

        foreach ($files as $file) {
            if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $offences[] = basename($file);
            }
        }

        $this->assertSame([], $offences, 'Golden rule 12: every file in src/ declares strict_types.');
    }

    public function test_app_stays_a_thin_shim(): void
    {
        $offences = [];

        foreach ($this->phpFilesIn('app') as $file) {
            $contents = SourceInspector::codeOf($file);

            // Names that give business logic away…
            if (preg_match('/class \w*(Handler|Command|Query|Service|Action|Repository)\b/', $contents) === 1) {
                $offences[] = basename($file).' looks like business logic';
            }

            // …and data access, which is the other way it sneaks in.
            //
            // The needle is Eloquent's NAMESPACE, not the bare word:
            // DomainServiceProvider imports `EloquentGameRepository` in order to
            // wire it up, which is exactly its job. What it cannot do is query
            // data.
            foreach (['Illuminate\\Database\\Eloquent', 'DB::', 'Cache::', '->where('] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offences[] = basename($file)." touches data ('{$needle}')";
                }
            }
        }

        $this->assertSame([], $offences, 'Business logic lives in src/, not in app/.');
    }

    public function test_the_framework_loop_lives_in_exactly_one_class(): void
    {
        // The headline failure of the audit that started this rebuild was that
        // nobody owned the phase, the turn cursor or the trident flag, so each
        // acquired contradictory definitions in different places. Two callers
        // that both materialise a stage's pool and both advance the cursor are
        // that failure with a new name, so the two calls that define the loop
        // belong to the aggregate and to nothing else — including a test double,
        // which is where the second one lived while the aggregate had no write
        // methods.
        $needles = ['TilePool::fromDeck(', 'SeatRing::next('];
        $callers = [];

        foreach ([...$this->phpFilesIn('src'), ...$this->phpFilesIn('tests/Unit/Game/Doubles')] as $file) {
            $code = SourceInspector::codeOf($file);

            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    $callers[] = basename($file);
                }
            }
        }

        // An empty scan is a failure: the aggregate itself has to be in there.
        $this->assertNotSame([], $callers, 'Neither call was found anywhere: this test is not testing anything.');
        $this->assertSame(['Game.php'], array_values(array_unique($callers)));
    }

    public function test_the_test_driver_of_the_loop_keeps_no_state_of_its_own(): void
    {
        // The string scan above names two calls, and a second loop that avoided
        // both spellings would pass it: a private cursor advanced by hand, for
        // instance, agreeing with the aggregate's often enough to stay green.
        //
        // What makes `RuleDriver` a wrapper is not which calls it avoids but that
        // it remembers nothing: the game it drives, the ruleset it drives it
        // with, and the two lists it accumulates the way the repository does —
        // `pullMoves()` empties the pending log, so a caller that wants the whole
        // history has to keep it. A field beyond those four is a decision this
        // class would be taking on its own.
        $code = SourceInspector::codeOf(self::root().'/tests/Unit/Game/Doubles/RuleDriver.php');

        preg_match_all('/\bprivate\s+(?:readonly\s+)?[^\s$]+\s+\$(\w+)/', $code, $matches);

        $fields = array_values(array_unique($matches[1]));
        sort($fields);

        $this->assertSame(['effects', 'game', 'moves', 'ruleSet'], $fields);
    }

    public function test_the_domain_never_depends_on_its_own_infrastructure(): void
    {
        $offences = [];

        foreach (glob(self::root().'/src/*/Domain', GLOB_ONLYDIR) ?: [] as $domain) {
            $context = basename(dirname($domain));

            foreach ($this->phpFilesIn("src/{$context}/Domain", mustHaveFiles: false) as $file) {
                $contents = SourceInspector::codeOf($file);

                if (preg_match('/^use Src\\\\\w+\\\\(Infrastructure|Application)\\\\/m', $contents) === 1) {
                    $offences[] = basename($file).' points outward';
                }
            }
        }

        $this->assertSame([], $offences, 'Dependencies point inward: Infrastructure → Application → Domain.');
    }

    public function test_nothing_tracked_names_a_stage_of_the_work(): void
    {
        /*
         * The repository is the product and never the project. A comment, a test
         * name or a document that says which instalment of the work something
         * belonged to is a note to us, dated the moment it was written and wrong
         * from the day after — and it tells a reader who arrives later something
         * they cannot act on.
         *
         * The planning lives outside both repositories on purpose, and this is
         * what keeps it from leaking back in one comment at a time.
         */
        $offences = [];
        $scanned = 0;

        foreach (self::TRACKED_DIRECTORIES as $directory) {
            foreach ($this->filesIn($directory) as $file) {
                $scanned++;

                $relative = str_replace(self::root().'/', '', $file);

                if ($relative === 'tests/Unit/ArchitectureTest.php') {
                    // This file names the word in order to forbid it.
                    continue;
                }

                $contents = (string) file_get_contents($file);

                if (preg_match('/'.self::DEVELOPMENT_STAGE_WORD.'[ _-]?\\d/i', $contents) === 1) {
                    $offences[] = $relative;
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'Nothing was scanned: this test is not testing anything.');
        $this->assertSame([], $offences, 'These tracked files name a stage of the work: '.implode(', ', $offences));
    }

    /**
     * Every file under a tracked directory, whatever its extension.
     *
     * Not just PHP: the word this guard looks for leaks into a compose file, a
     * shell script and a convention document exactly as easily.
     *
     * @return list<string>
     */
    private function filesIn(string $relative): array
    {
        $root = self::root().'/'.$relative;

        $this->assertDirectoryExists($root, "Expected to scan '{$relative}' but it does not exist.");

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
