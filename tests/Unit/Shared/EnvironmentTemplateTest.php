<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * The template and the code, held against each other.
 *
 * **A template that lies is worse than one that is short.** A variable listed
 * there but read by nothing invites somebody to set it and wonder for an hour
 * why nothing changed; a variable the code reads but the template never mentions
 * is a setting nobody knows exists until it is wrong in front of a room.
 *
 * Only this product's own variables are checked. Laravel's stock configuration
 * files reach for a hundred keys for mail, S3, Memcached and providers this
 * product does not use, and listing those would be noise pretending to be
 * completeness.
 */
final class EnvironmentTemplateTest extends TestCase
{
    /**
     * The prefixes this product is answerable for: a variable under one of these
     * that the template declares must be read by something.
     */
    private const OWNED = ['TRIDENT_', 'REVERB_', 'FRONTEND_'];

    /**
     * The prefixes the template must offer in full.
     *
     * Narrower than `OWNED`, and deliberately. `config/trident.php` is ours and
     * every key in it is a decision somebody may need to make. `config/reverb.php`
     * is vendor's: it reaches for a dozen tuning keys — scaling channels, pulse
     * ingest intervals — that have sane defaults nobody should have to think
     * about, and listing them all would be noise pretending to be completeness.
     */
    private const MUST_BE_OFFERED = ['TRIDENT_', 'FRONTEND_'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function template(): string
    {
        return (string) file_get_contents(self::root().'/.env.example');
    }

    /**
     * Every variable the template declares, whether or not it carries a value.
     *
     * @return list<string>
     */
    private function declared(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', self::template(), $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * Every variable this product's own configuration and its compose file read.
     *
     * @return list<string>
     */
    private function read(): array
    {
        $names = [];

        foreach ((array) glob(self::root().'/config/*.php') as $file) {
            preg_match_all("/env\('([A-Z][A-Z0-9_]*)'/", (string) file_get_contents($file), $matches);

            $names = [...$names, ...$matches[1]];
        }

        // Compose reads this same file, so a setting that only reaches a
        // container is as much a setting as one `config/` reads. Leaving them out
        // would let a service's own variables drift out of the template exactly
        // the way the rest of it already had.
        preg_match_all(
            '/\$\{([A-Z][A-Z0-9_]*)[:}]/',
            (string) file_get_contents(self::root().'/docker-compose.yml'),
            $fromCompose,
        );

        $names = [...$names, ...$fromCompose[1]];

        $owned = array_values(array_unique(array_filter($names, $this->isOurs(...))));
        sort($owned);

        return $owned;
    }

    private function isOurs(string $name): bool
    {
        foreach (self::OWNED as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function test_there_is_exactly_one_template_and_it_is_read_at_all(): void
    {
        $this->assertFileExists(self::root().'/.env.example');

        // One template and not one per environment: four files that had to agree
        // with each other is a thing that stops agreeing, and three of the four
        // had already drifted into another language and into shell syntax that
        // Laravel does not expand.
        $this->assertSame([], glob(self::root().'/.env.example.*') ?: []);

        $this->assertNotEmpty($this->declared());
    }

    public function test_every_setting_this_product_reads_is_in_the_template(): void
    {
        $required = array_values(array_filter(
            $this->read(),
            static function (string $name): bool {
                foreach (self::MUST_BE_OFFERED as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $missing = array_values(array_diff($required, $this->declared()));

        $this->assertSame(
            [],
            $missing,
            'These are read by config/ and absent from .env.example: '.implode(', ', $missing),
        );
    }

    public function test_the_template_declares_nothing_that_is_read_by_nobody(): void
    {
        $ours = array_values(array_filter($this->declared(), $this->isOurs(...)));
        $phantom = array_values(array_diff($ours, $this->read()));

        $this->assertSame(
            [],
            $phantom,
            'These are in .env.example and read by nothing, so setting one changes nothing: '
            .implode(', ', $phantom),
        );
    }

    public function test_the_seven_challenges_are_all_offered(): void
    {
        // Seven boxes, one per face. Six would be a deployment that could not set
        // one of them and would have no way of finding out which.
        for ($face = 0; $face <= 6; $face++) {
            $this->assertStringContainsString("TRIDENT_CHALLENGE_FACE_{$face}=", self::template());
        }
    }

    public function test_it_is_written_in_english_like_everything_else_tracked_here(): void
    {
        // The template was in Spanish while the code it describes was in English.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(el|la|los|las|una|para|que|con|por|desde|cambia|nunca|hasta)\b/i',
            self::template(),
        );
    }

    public function test_it_carries_no_shell_expansion_the_reader_will_not_expand(): void
    {
        // `${VAR:?}` is bash. Laravel reads it as those literal characters, so a
        // template written that way ships the placeholder as the value.
        $this->assertStringNotContainsString('${', self::template());
    }
}
