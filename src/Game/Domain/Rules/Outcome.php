<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * Everything a rule may ask the framework to do, and nothing else.
 *
 * The aggregate validates only what it owns — the game is running, there is a
 * current seat, the position exists and is not taken, the game has not finished
 * — then calls the ruleset and applies this. That is the entire coupling with
 * the rules.
 *
 * There is no `isStageComplete`, no `onStageComplete` and no `isFinished`, and
 * none may be added: `nextStage()` and `isFinished()` already express every
 * rule-driven transition (TR-18, TR-34), and two paths to one state change are
 * two paths that can disagree. Re-shuffling is not a member either: the
 * framework reacts to a next stage by asking `RuleSet::deck()` again (TR-31),
 * and the seat that opens it is `overrideNextSeat()` (TR-32).
 *
 * `pendingChoice()` is the one member that parks a game instead of moving it on:
 * the status becomes `GameStatus::AWAITING_CHOICE`, no position may be turned
 * over, and the answer comes back through `RuleSet::onChoiceMade()`.
 * `trident.v1` never returns one (TR-12). A pending choice and a finished game
 * are mutually exclusive in both directions: a game that is over waits for
 * nobody, and whichever the framework applied first would be an arbitrary
 * tiebreak. A pending choice and an overridden next seat are mutually exclusive
 * in both directions too: parking a turn does not move the cursor, so a seat
 * named beside a question could only be honoured after the answer, and the
 * answer's own outcome is where the ruleset says who plays next. A pending
 * choice **with** a next stage is legal and ordered: the framework moves to the
 * stage, materialises its pool, and then parks.
 *
 * Immutable: every `with…` returns the next outcome.
 */
final class Outcome implements JsonSerializable
{
    /**
     * @param  list<Effect>  $effects
     * @param  array<string, mixed>  $ruleStatePatch
     */
    private function __construct(
        private readonly array $effects,
        private readonly ?SeatNumber $overrideNextSeat,
        private readonly ?StageId $nextStage,
        private readonly array $ruleStatePatch,
        private readonly bool $finished,
        private readonly ?string $finishReason,
        private readonly ?PendingChoice $pendingChoice,
    ) {}

    /** Nothing happens: a legal draw that no rule reacts to. */
    public static function empty(): self
    {
        return new self([], null, null, [], false, null, null);
    }

    public static function of(Effect ...$effects): self
    {
        return new self(array_values($effects), null, null, [], false, null, null);
    }

    /** Effects are applied in the order they are added (TR-39). */
    public function withEffect(Effect $effect): self
    {
        return new self(
            [...$this->effects, $effect],
            $this->overrideNextSeat,
            $this->nextStage,
            $this->ruleStatePatch,
            $this->finished,
            $this->finishReason,
            $this->pendingChoice,
        );
    }

    /** The one way a rule moves the cursor. */
    public function withNextSeat(SeatNumber $seat): self
    {
        if ($this->pendingChoice !== null) {
            throw new InvalidArgumentException('A parked turn does not move the cursor; the answer names the next seat.');
        }

        return new self(
            $this->effects,
            $seat,
            $this->nextStage,
            $this->ruleStatePatch,
            $this->finished,
            $this->finishReason,
            $this->pendingChoice,
        );
    }

    public function withNextStage(StageId $stage): self
    {
        if ($this->finished) {
            throw new InvalidArgumentException('A finished game does not move to another stage.');
        }

        return new self(
            $this->effects,
            $this->overrideNextSeat,
            $stage,
            $this->ruleStatePatch,
            $this->finished,
            $this->finishReason,
            $this->pendingChoice,
        );
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function withRuleStatePatch(array $patch): self
    {
        RuleState::assertPatch($patch);

        return new self(
            $this->effects,
            $this->overrideNextSeat,
            $this->nextStage,
            [...$this->ruleStatePatch, ...$patch],
            $this->finished,
            $this->finishReason,
            $this->pendingChoice,
        );
    }

    /** A game never finishes without a reason: the two are set together. */
    public function finishedBecause(string $reason): self
    {
        if (! FinishReason::isValid($reason)) {
            throw new InvalidArgumentException("'{$reason}' is not a finish reason.");
        }

        if ($this->nextStage !== null) {
            throw new InvalidArgumentException('A finished game does not move to another stage.');
        }

        if ($this->pendingChoice !== null) {
            throw new InvalidArgumentException('A finished game does not wait for an answer.');
        }

        return new self(
            $this->effects,
            $this->overrideNextSeat,
            null,
            $this->ruleStatePatch,
            true,
            $reason,
            null,
        );
    }

    /**
     * Parks the game on a question one named seat has to answer, which comes
     * back through `RuleSet::onChoiceMade()`.
     */
    public function withPendingChoice(PendingChoice $choice): self
    {
        if ($this->finished) {
            throw new InvalidArgumentException('A finished game does not wait for an answer.');
        }

        if ($this->overrideNextSeat !== null) {
            throw new InvalidArgumentException('A parked turn does not move the cursor; the answer names the next seat.');
        }

        return new self(
            $this->effects,
            $this->overrideNextSeat,
            $this->nextStage,
            $this->ruleStatePatch,
            false,
            null,
            $choice,
        );
    }

    /**
     * @return list<Effect>
     */
    public function effects(): array
    {
        return $this->effects;
    }

    public function overrideNextSeat(): ?SeatNumber
    {
        return $this->overrideNextSeat;
    }

    public function nextStage(): ?StageId
    {
        return $this->nextStage;
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleStatePatch(): array
    {
        return $this->ruleStatePatch;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    public function pendingChoice(): ?PendingChoice
    {
        return $this->pendingChoice;
    }

    /**
     * Every key always present, with an explicit null where there is nothing: a
     * key that is sometimes there is a contract nobody can type.
     *
     * @return array{
     *     effects: list<array<string, mixed>>,
     *     override_next_seat: int|null,
     *     next_stage: string|null,
     *     pending_choice: array{seat: int, prompt_key: string, options: list<string>}|null,
     *     rule_state_patch: array<string, mixed>,
     *     finished: bool,
     *     finish_reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'effects' => array_map(static fn (Effect $effect): array => $effect->toArray(), $this->effects),
            'override_next_seat' => $this->overrideNextSeat?->value(),
            'next_stage' => $this->nextStage?->value(),
            'pending_choice' => $this->pendingChoice?->toArray(),
            'rule_state_patch' => $this->ruleStatePatch,
            'finished' => $this->finished,
            'finish_reason' => $this->finishReason,
        ];
    }

    /**
     * @return array{
     *     effects: list<array<string, mixed>>,
     *     override_next_seat: int|null,
     *     next_stage: string|null,
     *     pending_choice: array{seat: int, prompt_key: string, options: list<string>}|null,
     *     rule_state_patch: array<string, mixed>,
     *     finished: bool,
     *     finish_reason: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
