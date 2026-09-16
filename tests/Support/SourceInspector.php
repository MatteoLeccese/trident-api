<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads source code for the structural guards.
 *
 * It exists because grepping a raw file confuses prose with code: a docblock that
 * *explains* why `Cache::` is not used here made the guard that forbids `Cache::`
 * fail. It has happened twice.
 */
final class SourceInspector
{
    /** The file's code, without comments. Strings are kept: a framework class name
     * inside a string is still a real dependency. */
    public static function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    public static function phpFilesIn(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
