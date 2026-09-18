<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Game\Infrastructure\Http\Controllers\GameController;
use Src\Game\Infrastructure\Http\Middleware\VerifyControllerToken;

Route::prefix('games')->group(function (): void {
    // Create is the only mutation without a token: it is the one that issues it.
    Route::post('/', [GameController::class, 'store'])->middleware('throttle:10,1');

    // CAREFUL: the literal segment goes BEFORE the dynamic one, or `{gameId}`
    // captures it and the television never finds the game by code.
    Route::get('/by-code/{code}', [GameController::class, 'showByCode'])->middleware('throttle:20,1');

    Route::get('/{gameId}', [GameController::class, 'show']);

    // The lobby form's declaration. A GET, so the mutation sweeper of
    // `GameEndpointsTest` skips it — it skips a route whose only method is GET —
    // and it demands no controller token: the declaration is labels and defaults,
    // the same public bytes the settings themselves are.
    Route::get('/{gameId}/room-config-spec', [GameController::class, 'roomConfigSpec']);

    Route::middleware(VerifyControllerToken::class)->group(function (): void {
        // The literal `order` goes BEFORE `{seat}` for the same reason `by-code`
        // goes before `{gameId}`. The two differ by method today, so nothing
        // captures anything; declaring them in this order is what keeps that true
        // if either verb ever changes.
        Route::put('/{gameId}/seats/order', [GameController::class, 'reorderSeats'])
            ->middleware('throttle:30,1');

        Route::patch('/{gameId}/seats/{seat}', [GameController::class, 'renameSeat'])
            ->whereNumber('seat');

        // Lobby-only writes. No route says so and no column says so: a status is
        // framework state, and the aggregate is what refuses them once play has
        // begun.
        Route::post('/{gameId}/room-config', [GameController::class, 'configureRoom']);

        Route::post('/{gameId}/start', [GameController::class, 'start']);

        // `whereNumber` restricts the segment to `[0-9]+`, so a non-numeric
        // segment is a 404 from the constraint and never reaches the handler,
        // while a numeric position outside this pool does and comes back as
        // 422 pool_position_not_in_pool. Two failure modes, two paths.
        Route::post('/{gameId}/pool/{position}/draw', [GameController::class, 'draw'])
            ->whereNumber('position');

        // The second and last route that emits a plaintext controller token. It
        // is not an exception to the sweeper: it demands the token of the game in
        // play in order to issue the next game's.
        Route::post('/{gameId}/play-again', [GameController::class, 'playAgain']);
    });
});
