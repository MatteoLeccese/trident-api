<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        'Cache::',
        'DB::',
        'config(',
        'app(',
        'env(',
    ];

    /** Contexts that must exist. Adding a new one here is part of creating it. */
    private const EXPECTED_CONTEXTS = ['Game', 'Realtime', 'Shared'];

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

            // A context with no files yet is legitimate (Game and Realtime until
            // phase 2), but the directory has to exist.
            foreach ($this->phpFilesIn("src/{$context}/Domain", mustHaveFiles: false) as $file) {
                $scanned++;
                $contents = SourceInspector::codeOf($file);

                foreach (self::FORBIDDEN_IN_DOMAIN as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offences[] = "src/{$context}/Domain/".basename($file)." contains '{$needle}'";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No domain file was scanned.');
        $this->assertSame([], $offences, 'The domain must be plain PHP.');
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
}
