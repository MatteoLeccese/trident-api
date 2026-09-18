<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Application\Query\GetRoomConfigSpec\GetRoomConfigSpecQuery;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeQuery;
use Src\Game\Infrastructure\Http\Requests\ConfigureRoomRequest;
use Src\Game\Infrastructure\Http\Requests\CreateGameRequest;
use Src\Game\Infrastructure\Http\Requests\DrawTileRequest;
use Src\Game\Infrastructure\Http\Requests\PlayAgainRequest;
use Src\Game\Infrastructure\Http\Requests\RenameSeatRequest;
use Src\Game\Infrastructure\Http\Requests\ReorderSeatsRequest;
use Src\Game\Infrastructure\Http\Requests\StartGameRequest;
use Src\Shared\Domain\Bus\CommandBus;
use Src\Shared\Domain\Bus\QueryBus;
use Src\Shared\Infrastructure\Http\ApiResponse;

/**
 * Thin controller: one call to the bus and an `ApiResponse`. No logic, no Eloquent,
 * no try/catch — typed exceptions are turned into the envelope by the global
 * handler.
 */
final class GameController extends Controller
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function store(CreateGameRequest $request): JsonResponse
    {
        $created = $this->commands->dispatch($request->toCommand());

        return ApiResponse::created([
            'game' => $created->snapshot->toArray(),
            // The BFF stores it in an httpOnly cookie and strips it from here before
            // the response reaches the browser.
            'controller_token' => $created->controllerToken->value(),
        ], 'Game created.');
    }

    public function show(string $gameId): JsonResponse
    {
        return ApiResponse::success(
            $this->queries->ask(new GetGameStateQuery($gameId))->toArray(),
        );
    }

    /**
     * The settings this game's ruleset declares, which the lobby form is
     * generated from.
     *
     * A route of its own and not a field of the snapshot, for three reasons that
     * all point the same way:
     *
     *  - **A lobby is pinned to no ruleset.** `games.rule_set_id` is null until
     *    `start()`, and `GameProjector` refuses to resolve a default for a read
     *    on purpose, so that a read of a lobby cannot change meaning because a
     *    deployment changed a key between two requests. A spec in the snapshot
     *    would need exactly that resolution on every lobby read.
     *  - **It is not state.** It carries no version, no write changes it, and it
     *    is identical for every game the same ruleset decides. The snapshot is
     *    the shape whose every field belongs to one version
     *    (documentation/conventions/state-versioning.md), and a field that never
     *    moves would ride 49 positions' worth of frames for a form that is read
     *    once.
     *  - **The form and the validator are one declaration.** Both this and
     *    `configureRoom()` reach it through `GameRules`, so the boxes the lobby
     *    paints are the boxes the submission is checked against.
     *
     * It is public, like the settings it declares: they are painted on a
     * television that every guest photographs
     * (documentation/conventions/room-config.md).
     */
    public function roomConfigSpec(string $gameId): JsonResponse
    {
        return ApiResponse::success(
            $this->queries->ask(new GetRoomConfigSpecQuery($gameId))->toArray(),
        );
    }

    public function showByCode(string $code): JsonResponse
    {
        return ApiResponse::success(
            $this->queries->ask(new ResolveJoinCodeQuery($code))->toArray(),
        );
    }

    public function renameSeat(RenameSeatRequest $request, string $gameId, int $seat): JsonResponse
    {
        return ApiResponse::success(
            $this->commands->dispatch($request->toCommand($gameId, $seat))->toArray(),
            'Seat renamed.',
        );
    }

    public function configureRoom(ConfigureRoomRequest $request, string $gameId): JsonResponse
    {
        return ApiResponse::success(
            $this->commands->dispatch($request->toCommand($gameId))->toArray(),
            'Table settings saved.',
        );
    }

    public function reorderSeats(ReorderSeatsRequest $request, string $gameId): JsonResponse
    {
        return ApiResponse::success(
            $this->commands->dispatch($request->toCommand($gameId))->toArray(),
            'Seats reordered.',
        );
    }

    public function start(StartGameRequest $request, string $gameId): JsonResponse
    {
        return ApiResponse::success(
            $this->commands->dispatch($request->toCommand($gameId))->toArray(),
            'Game started.',
        );
    }

    /**
     * The position stays a string all the way to the domain: `whereNumber`
     * restricts the segment to digits, and whether those digits are a canonical
     * position is `PoolPosition`'s answer and not a cast's.
     */
    public function draw(DrawTileRequest $request, string $gameId, string $position): JsonResponse
    {
        return ApiResponse::success(
            $this->commands->dispatch($request->toCommand($gameId, $position))->toArray(),
            'Tile drawn.',
        );
    }

    public function playAgain(PlayAgainRequest $request, string $gameId): JsonResponse
    {
        $created = $this->commands->dispatch($request->toCommand($gameId));

        return ApiResponse::created([
            'game' => $created->snapshot->toArray(),
            // The second and last route that emits it. The BFF stores it in the
            // httpOnly cookie, rotating the previous game's, and strips it from
            // here before the response reaches the browser.
            'controller_token' => $created->controllerToken->value(),
        ], 'Game created.');
    }
}
