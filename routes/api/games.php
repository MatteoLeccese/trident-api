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

    Route::middleware(VerifyControllerToken::class)->group(function (): void {
        Route::patch('/{gameId}/seats/{seat}', [GameController::class, 'renameSeat'])
            ->whereNumber('seat');
    });
});
