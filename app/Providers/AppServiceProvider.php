<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
          * Without a named limiter `throttle:api` does not exist and no route can
          * be limited at all.
          *
          * **Keyed on the game and not on the caller.** There are no users in this
          * product to key on, and the IP is worse than useless here: every request
          * arrives from the frontend container, so a table of fifteen phones and
          * televisions would share one bucket with every other table in the house
          * and the first party to fill it would silence the rest. The game is the
          * granularity this product actually has — one table, one bucket — and a
          * table that floods its own bucket slows nobody else down.
          *
          * A request that names no game (creating one, resolving a join code)
          * falls back to the caller, which those two routes already limit far more
          * tightly on their own.
          */
        RateLimiter::for('api', function (Request $request): Limit {
            $gameId = $request->route('gameId');

            return is_string($gameId)
                ? Limit::perMinute(240)->by('game:'.$gameId)
                : Limit::perMinute(120)->by('caller:'.($request->ip() ?? 'unknown'));
        });
    }
}
