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
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        // This product's authorisation is per-game credentials, and a middleware
        // resolves them. There is nothing to decide here.
        return true;
    }
}
