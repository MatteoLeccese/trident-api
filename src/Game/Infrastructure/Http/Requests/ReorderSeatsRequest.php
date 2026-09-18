<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\ReorderSeats\ReorderSeatsCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

/**
 * Shape only: a list of seat numbers. That the list names every seat of this
 * table exactly once is an invariant of the roster, so it holds for a caller
 * that never went through HTTP and comes back as `seat_order_invalid`.
 *
 * `integer|min:1` is here and not there because a seat number below one is not a
 * wrong permutation, it is not a seat number: seats are 1..N everywhere, forever.
 */
final class ReorderSeatsRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            ...self::WRITE_RULES,
            'order' => 'required|array',
            'order.*' => 'required|integer|min:1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => 'The new order is missing.',
            'order.array' => 'The new order must be a list of seats.',
            'order.*.required' => 'One of the seats in that order is missing.',
            'order.*.integer' => 'A seat in that order is not a seat number.',
            'order.*.min' => 'A seat in that order is not a seat number.',
        ];
    }

    public function toCommand(string $gameId): ReorderSeatsCommand
    {
        /** @var list<int> $order */
        $order = array_map(intval(...), array_values($this->validated()['order']));

        return new ReorderSeatsCommand($gameId, $order, $this->expectedVersion(), $this->requestId());
    }
}
