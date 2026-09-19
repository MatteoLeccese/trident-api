<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules\Trident;

use InvalidArgumentException;

/**
 * The seven phrases a table starts with, one per face.
 *
 * **They are a per-deployment value, not a rule.** When a challenge fires is a
 * rule and lives in `TridentRuleSet`; what it says is a setting, and the table
 * may rewrite every one of them without changing a single draw (TR-53). These
 * are only the defaults the room finds already in the boxes, which is why they
 * can be set per deployment and why changing them cannot change a game that has
 * already started: `start()` freezes the resolved settings into the row.
 *
 * **An override that cannot be painted is refused, loudly and at once.** A blank
 * or absent variable is not an override at all and takes the shipped phrase; but
 * a phrase longer than a television can hold, or one carrying a line break, is a
 * deployment mistake, and the place to find out is the moment the container
 * comes up rather than the moment a card lands in front of a room. `RoomConfig`
 * validates what the *table* writes against the same declaration; nothing
 * validated what the deployment wrote, because until now nothing could write it.
 */
final class ChallengeTexts
{
    /**
     * @param  array<int, string>  $byFace
     */
    private function __construct(private readonly array $byFace) {}

    /**
     * The phrases this build ships with.
     *
     * They are placeholders of the right shape and length, and they say so. The
     * real ones are the author's to write, and a party game whose cards read like
     * this is a party game nobody plays — which is exactly what the text is meant
     * to make obvious on the first television it reaches.
     */
    public static function shipped(): self
    {
        $texts = [];

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $texts[$face] = "Placeholder challenge for face {$face}: the table writes this one.";
        }

        return new self($texts);
    }

    /**
     * The shipped phrases with the deployment's own put over them.
     *
     * An entry that is absent, null or blank is not an override: nobody set that
     * variable, and the shipped phrase stands. Anything else has to be a phrase
     * this product can actually paint.
     *
     * @param  array<int|string, mixed>  $overrides  by face, as the configuration carries them
     */
    public static function fromOverrides(array $overrides): self
    {
        $texts = self::shipped()->byFace;

        foreach ($texts as $face => $shipped) {
            $override = $overrides[$face] ?? null;

            if (! is_string($override) || trim($override) === '') {
                continue;
            }

            $texts[$face] = self::assertPaintable(trim($override), $face);
        }

        return new self($texts);
    }

    public function of(int $face): string
    {
        return $this->byFace[$face] ?? throw new InvalidArgumentException("There is no challenge for face {$face}.");
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->byFace;
    }

    private static function assertPaintable(string $text, int $face): string
    {
        if (preg_match('/[\p{C}]/u', $text) === 1) {
            throw new InvalidArgumentException(
                "The challenge for face {$face} carries a control character. It is painted as one line on a television."
            );
        }

        if (mb_strlen($text) > TridentRuleSet::CHALLENGE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The challenge for face %d is %d characters. The most a table can hold is %d.',
                $face,
                mb_strlen($text),
                TridentRuleSet::CHALLENGE_MAX_LENGTH,
            ));
        }

        return $text;
    }
}
