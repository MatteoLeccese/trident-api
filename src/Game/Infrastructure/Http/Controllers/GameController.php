<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeQuery;
use Src\Game\Infrastructure\Http\Requests\CreateGameRequest;
use Src\Game\Infrastructure\Http\Requests\RenameSeatRequest;
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
}
