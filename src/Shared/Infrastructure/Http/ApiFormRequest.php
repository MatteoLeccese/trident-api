<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of every FormRequest.
 *
 * `rules()` validates only the **shape**: that the JSON looks right. The business
 * rules — how many people fit, whether a name is duplicated — live in the domain,
 * not here, because they are the same whether you arrive over HTTP or console.
 *
 * `$stopOnFirstFailure` because the envelope carries a single message: the client
 * branches on the code, not on the text.
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * The shape of the two guards every mutation may carry, merged into the
     * rules of the requests that are mutations.
     *
     * `expected_version` is optional and honoured when it is there: a client that
     * sends it gets refused with the current state when it is stale, and one that
     * does not sends its tap the way the first phase's routes always did. What it
     * may never be is coerced — a zero or a word is a validation error and never
     * a silently ignored guard.
     */
    protected const WRITE_RULES = ['expected_version' => 'nullable|integer|min:1'];

    /** The header the idempotency ledger is keyed by: `game_moves.request_id`. */
    private const REQUEST_ID_HEADER = 'X-Request-Id';

    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        // This product's authorisation is per-game credentials, and a middleware
        // resolves them. There is nothing to decide here.
        return true;
    }

    /**
     * The version the client believes it is writing on top of, or null when it
     * carries no guard.
     */
    protected function expectedVersion(): ?int
    {
        $value = $this->validated()['expected_version'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * The write intention, raw. Its shape is not checked here: the same check
     * has to run for a caller that never went through HTTP, so it lives one
     * layer in, where a malformed id becomes `422 request_id_invalid`.
     */
    protected function requestId(): ?string
    {
        $raw = $this->header(self::REQUEST_ID_HEADER);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
