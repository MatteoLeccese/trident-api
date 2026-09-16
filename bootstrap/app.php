<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
    ->withCommands([SmokeCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // Safety net: enforces the envelope on any response that did not go out
        // through ApiResponse.
        $middleware->api(append: [ApiResponseMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApi): void {
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
                ? ApiResponse::error('unauthenticated', 'Autenticación requerida.', 401)
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

        // The SQL and its bindings never leave here, not even with debug turned on.
        $exceptions->render(function (QueryException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $reference = bin2hex(random_bytes(8));
            Log::error("[{$reference}] QueryException", ['exception' => $e]);

            return ApiResponse::error(
                'database_error',
                'Ha ocurrido un error inesperado.',
                500,
                ['ref' => $reference],
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

            return ApiResponse::error('internal_error', 'Ha ocurrido un error inesperado.', 500, $data);
        });
    })->create();
