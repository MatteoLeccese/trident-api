<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Src\Game\Infrastructure\Console\ExpireGamesCommand;
use Src\Game\Infrastructure\Console\TallyGamesCommand;
use Src\Realtime\Infrastructure\Console\SmokeCommand;
use Src\Realtime\Infrastructure\Http\Middleware\ResolveGameParticipant;
use Src\Shared\Domain\Exceptions\DomainException;
use Src\Shared\Infrastructure\Http\ApiResponse;
use Src\Shared\Infrastructure\Http\ApiResponseMiddleware;
use Src\Shared\Infrastructure\Http\HttpErrorCode;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * Centralized error handling.
 *
 * No try/catch to shape an HTTP response: a typed exception is thrown and the
 * global handler turns it into the envelope.
 *
 * Two rules govern this file:
 *
 * 1. Laravel runs `prepareException()` BEFORE evaluating these callbacks, so
 *    `AuthorizationException` arrives as `AccessDeniedHttpException` and
 *    `ModelNotFoundException` as `NotFoundHttpException`. Registering callbacks
 *    for the original types is dead code. That is why `HttpExceptionInterface`
 *    is covered in one go: it is structural, not an enumeration that somebody
 *    has to remember to extend.
 *
 * 2. An exception's message NEVER crosses the wire. `abort(409, '...')` carries
 *    developer text and `QueryException` carries the SQL with its bindings.
 *    What is returned is a `ref` with which to find the detail in the log.
 *
 * Callbacks are evaluated in registration order, so the specific ones go first
 * and the \Throwable catch-all goes last.
 */

$isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

/*
 * The reference a client is handed and the reference the log carries, derived
 * from the exception itself so that the two callbacks that need it agree without
 * one of them having to run first or hand anything to the other.
 */
$referenceFor = fn (Throwable $e): string => substr(hash('sha256', spl_object_hash($e)), 0, 16);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * CAREFUL: `withBroadcasting` is used and NOT `withRouting(channels: ...)`.
     *
     * `withRouting` delegates without attributes, so Laravel falls back to its
     * default `['middleware' => ['web']]`: the route would end up without the
     * `api/v1` prefix, without ResolveGameParticipant, and inside the `web`
     * group. The `$attributes` REPLACE the default, they are never merged.
     *
     * Losing the `web` group here is what we want: no EncryptCookies, no
     * session, no cookie churn.
     *
     * And `routes/channels.php` HAS to exist: if it is missing, the
     * registration is skipped silently and all you see is 403s with no trace in
     * any log.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: [
            'prefix' => 'api/v1',
            // By FQCN and not by alias: an unregistered alias is another mute 403.
            'middleware' => [ResolveGameParticipant::class, 'throttle:60,1'],
        ],
    )
    ->withCommands([ExpireGamesCommand::class, TallyGamesCommand::class, SmokeCommand::class])

    /*
     * The one scheduled thing in the product.
     *
     * Every five minutes against a window measured in hours: the sweep is cheap
     * when it finds nothing, and the resolution it needs is "the table left an
     * hour ago", not "the table left ninety seconds ago". `withoutOverlapping`
     * because a sweep that walks a backlog can outlive its own interval, and two
     * of them racing would take the same lock twice for nothing.
     *
     * It runs in the `scheduler` container and nowhere else. Without that
     * container this line executes never, which is why the two ship together.
     */
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('trident:expire-games')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Safety net: enforces the envelope on any response that did not go out
        // through ApiResponse.
        $middleware->api(append: [ApiResponseMiddleware::class]);

        // The API takes its payloads as they were sent. Laravel's global
        // conversion turns every "" into null before a FormRequest sees it, and
        // this product has two places where the two are different answers: an
        // empty challenge text is a face that announces nothing
        // (documentation/conventions/room-config.md), and an `expected_version`
        // of "" is a malformed guard, which has to be refused rather than read
        // as "no guard".
        $middleware->convertEmptyStringsToNull(except: [
            static fn (Request $request): bool => $request->is('api/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApi, $referenceFor): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A business failure is expected. It does not pollute the log.
        $exceptions->dontReport(DomainException::class);
        $exceptions->dontReportDuplicates();

        $exceptions->render(function (DomainException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error($e->errorCode(), $e->getMessage(), $e->status(), $e->data())
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            // Only the first message: the client branches on the code, not on the text.
            return $isApi($request)
                ? ApiResponse::error(
                    'validation_error',
                    (string) Arr::first(Arr::flatten($e->errors())),
                    422,
                )
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('unauthenticated', 'Authentication required.', 401)
                : null;
        });

        // Before the generic HttpException one, so as to keep Retry-After and
        // X-RateLimit-*, which are what tell the client how long to wait.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('too_many_requests', HttpErrorCode::messageForStatus(429), 429)
                ->withHeaders($e->getHeaders());
        });

        /*
         * A database failure is logged HERE and by nothing else, and what is
         * logged is never the exception.
         *
         * `QueryException` builds its message by interpolating the bindings into
         * the statement, and every write of a game binds `games.shuffle_seed`:
         * the default reporter logs `$e->getMessage()`, which would put the one
         * secret of the system (TR-09) in cleartext in storage/logs — a store
         * that is shipped, read and pasted into tickets. Returning false is what
         * keeps it out of that default stack, and this callback runs wherever the
         * exception is raised, which a render callback does not.
         *
         * The statement with its placeholders says which query failed, the
         * driver's own exception says why, and neither carries a bound value.
         */
        $exceptions->report(function (QueryException $e) use ($referenceFor): bool {
            Log::error("[{$referenceFor($e)}] QueryException", [
                'sql' => $e->getSql(),
                'class' => $e::class,
                'code' => $e->getCode(),
                'driver' => $e->getPrevious()?->getMessage(),
            ]);

            return false;
        });

        // The SQL and its bindings never leave here either, not even with debug
        // turned on: the client is handed the reference and nothing else.
        $exceptions->render(function (QueryException $e, Request $request) use ($isApi, $referenceFor) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error(
                'database_error',
                'Something went wrong.',
                500,
                ['ref' => $referenceFor($e)],
            );
        });

        // Structural coverage of every HttpException: 403, 404, 405, abort(409),
        // 413, 419, 503… including the ones that do not exist yet.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            return ApiResponse::error(
                HttpErrorCode::forStatus($status),
                HttpErrorCode::messageForStatus($status),
                $status,
            )->withHeaders($e->getHeaders());
        });

        // Catch-all. Returns a reference, never the detail: the developer finds
        // the cause by grepping the ref in storage/logs.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $reference = bin2hex(random_bytes(8));
            Log::error("[{$reference}] ".$e::class, ['exception' => $e]);

            $data = ['ref' => $reference];

            // The exception class helps with debugging and contains no data.
            // The message can indeed contain data, so it is never included.
            if (app()->environment('local', 'testing') && config('app.debug') === true) {
                $data['debug'] = ['exception' => $e::class];
            }

            return ApiResponse::error('internal_error', 'Something went wrong.', 500, $data);
        });
    })->create();
