<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * A decision a rule stops the game for: one named seat, one question, and the
 * closed list of answers that seat may give.
 *
 * While one is pending the game's status is `GameStatus::AWAITING_CHOICE` and no
 * position may be turned over. The answer re-enters the rules through
 * `RuleSet::onChoiceMade()`, which is the only reason that method exists: a
 * choice nothing reads back is not a choice.
 *
 * `trident.v1` never returns one (TR-12), and the class is not speculative: the
 * hostile double of `tests/Unit/Game/Doubles/` demands it, which is the
 * condition documentation/conventions/rule-set-seam.md sets for building it.
 *
 * **The seat is never null.** A null seat means "the whole table" in
 * `Effect::challenge`, and a question the whole table answers has no single
 * answer to wait for — that is a vote, which is a different mechanism and not
 * this one.
 *
 * **The prompt and the options are message keys of the application's own copy**,
 * the same space `Effect::announce` indexes, and never a room configuration key.
 * A button the framework parks the game on is not painted with text the table
 * wrote: that copy is untrusted and bounded, and a rule that wants it alongside
 * emits an `Effect::challenge` in the same outcome.
 */
final class PendingChoice implements JsonSerializable
{
    /** The copy the application ships, addressed by a dotted key of its space. */
    private const KEY_PATTERN = '/\A[a-z0-9_]+(\.[a-z0-9_]+)*\z/';

    /** Two answers or it is not a choice. */
    private const MIN_OPTIONS = 2;

    /**
     * @param  list<string>  $options
     */
    private function __construct(
        private readonly SeatNumber $seat,
        private readonly string $promptKey,
        private readonly array $options,
    ) {}

    /**
     * @param  list<string>  $options
     */
    public static function of(SeatNumber $seat, string $promptKey, array $options): self
    {
        if (preg_match(self::KEY_PATTERN, $promptKey) !== 1) {
            throw new InvalidArgumentException("'{$promptKey}' is not a message key.");
        }

        $options = array_values($options);

        foreach ($options as $option) {
            if (! is_string($option) || preg_match(self::KEY_PATTERN, $option) !== 1) {
                throw new InvalidArgumentException('An option of a choice is a message key.');
            }
        }

        if (count($options) < self::MIN_OPTIONS || $options !== array_unique($options)) {
            throw new InvalidArgumentException('A choice offers at least two distinct options.');
        }

        return new self($seat, $promptKey, $options);
    }

    public function seat(): SeatNumber
    {
        return $this->seat;
    }

    public function promptKey(): string
    {
        return $this->promptKey;
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function allows(string $option): bool
    {
        return in_array($option, $this->options, true);
    }

    /**
     * @return array{seat: int, prompt_key: string, options: list<string>}
     */
    public function toArray(): array
    {
        return [
            'seat' => $this->seat->value(),
            'prompt_key' => $this->promptKey,
            'options' => $this->options,
        ];
    }

    /**
     * @return array{seat: int, prompt_key: string, options: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
