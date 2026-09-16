<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Safety net for the envelope.
 *
 * Controllers return `ApiResponse::*` and come out fine already. This exists for
 * what slips through: an absent-minded `return ['ok' => true]`, a `return 'ok'`
 * that Laravel turns into plain text, a third-party package that responds its own
 * way. Without this net, that kind of response breaks the contract silently, until
 * the frontend tries to read `data` and finds something else.
 *
 * It does not touch what deliberately is not an envelope: downloads, streaming
 * responses, redirects and responses with no body (204, 304).
 */
final class ApiResponseMiddleware
{
    private const ENVELOPE_KEYS = ['status', 'message', 'error', 'data'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldBeLeftAlone($response)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($response instanceof JsonResponse) {
            $body = $response->getData(true);

            if ($this->isEnvelope($body)) {
                return $response;
            }

            return $this->wrap($body, $status, $response);
        }

        // Plain text response: `return 'ok'` or `return null` in a controller.
        $content = $response->getContent();
        $body = ($content === false || $content === '') ? null : $content;

        return $this->wrap($body, $status, $response);
    }

    private function shouldBeLeftAlone(Response $response): bool
    {
        return $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->isRedirection()
            || $response->isEmpty();
    }

    private function wrap(mixed $body, int $status, Response $original): JsonResponse
    {
        // An error response that comes without an envelope cannot invent a credible
        // machine code: it is given the one that corresponds to its status.
        $wrapped = $status >= 400
            ? ApiResponse::error(
                HttpErrorCode::forStatus($status),
                HttpErrorCode::messageForStatus($status),
                $status,
            )
            : ApiResponse::success($body);

        // Meaningful headers are preserved (Retry-After, X-RateLimit-*) without
        // dragging along the ones that describe the body we have just replaced.
        foreach ($original->headers->all() as $name => $values) {
            if (! in_array(strtolower($name), ['content-type', 'content-length'], true)) {
                $wrapped->headers->set($name, $values);
            }
        }

        return $wrapped;
    }

    private function isEnvelope(mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        foreach (self::ENVELOPE_KEYS as $key) {
            if (! array_key_exists($key, $body)) {
                return false;
            }
        }

        return true;
    }
}
