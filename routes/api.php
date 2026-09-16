<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Src\Shared\Infrastructure\Http\ApiResponse;

/*
 * A single version prefix, declared here and nowhere else.
 * A new version = a new `v2` group + its own routes folder.
 */
Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', fn () => ApiResponse::success(['status' => 'ok']));

    require __DIR__.'/api/games.php';
});
