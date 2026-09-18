<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;

/**
 * The stages a ruleset declares, in order, each with the label a screen paints.
 *
 * Immutable: `then()` returns the next sequence.
 *
 * The order is the ruleset's declaration order and nothing else: the framework
 * never advances a stage by walking this list. Every stage transition is a
 * ruleset's `Outcome::nextStage()`, which is free to point backwards, so a
 * sequence does not publish "the stage after this one".
 *
 * The label is copy the ruleset ships, which is why the client can render a
 * stage it has never heard of.
 */
final class StageSequence implements JsonSerializable
{
    /**
     * @param  list<StageId>  $stages
     * @param  array<string, string>  $labels  keyed by stage id
     */
    private function __construct(private readonly array $stages, private readonly array $labels) {}

    public static function of(StageId $stage, string $label): self
    {
        return new self([$stage], [$stage->value() => self::assertLabel($label)]);
    }

    public function then(StageId $stage, string $label): self
    {
        if ($this->has($stage)) {
            throw new InvalidArgumentException("The stage '{$stage->value()}' is declared twice.");
        }

        return new self(
            [...$this->stages, $stage],
            [...$this->labels, $stage->value() => self::assertLabel($label)],
        );
    }

    /**
     * @return list<StageId>
     */
    public function all(): array
    {
        return $this->stages;
    }

    public function first(): StageId
    {
        return $this->stages[0];
    }

    public function has(StageId $stage): bool
    {
        return isset($this->labels[$stage->value()]);
    }

    public function labelOf(StageId $stage): string
    {
        if (! $this->has($stage)) {
            throw new InvalidArgumentException("The stage '{$stage->value()}' is not in this sequence.");
        }

        return $this->labels[$stage->value()];
    }

    /**
     * Array of objects with an explicit `id`, never a positional map.
     *
     * @return list<array{id: string, label: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (StageId $stage): array => ['id' => $stage->value(), 'label' => $this->labelOf($stage)],
            $this->stages,
        );
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function assertLabel(string $label): string
    {
        // Control characters are rejected: a line break in a label breaks any
        // layout and is not an oversight.
        if (trim($label) === '' || preg_match('/[\p{C}]/u', $label) === 1) {
            throw new InvalidArgumentException('A stage label cannot be empty or contain control characters.');
        }

        return $label;
    }
}
