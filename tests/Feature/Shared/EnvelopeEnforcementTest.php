<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Src\Shared\Domain\Exceptions\BusinessException;
use Src\Shared\Infrastructure\Http\ApiResponse;
use Tests\TestCase;

/**
 * `backend.md` mandates a middleware that enforces the envelope globally: it wraps
 * whatever escapes and turns garbage into a 500. Without it, any stray `return
 * ['ok' => true]` breaks the contract without anyone noticing.
 */
final class EnvelopeEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/__env')->group(function (): void {
            Route::get('/bare-array', fn () => ['seat' => 3]);
            Route::get('/bare-string', fn () => 'ok');
            Route::get('/already-wrapped', fn () => ApiResponse::success(['seat' => 3], 'Done'));
            Route::get('/nothing', fn () => null);
            Route::get('/no-content', fn () => response()->noContent());
            Route::get('/business', fn () => throw new BusinessException('not_your_turn', 'It is not your turn.'));
            Route::get('/limited', fn () => ApiResponse::success())->middleware('throttle:2,1');
        });
    }

    public function test_a_stray_array_gets_wrapped_in_the_envelope(): void
    {
        $this->getJson('/api/v1/__env/bare-array')
            ->assertOk()
            ->assertExactJson([
                'status' => 200,
                'message' => 'OK',
                'error' => null,
                'data' => ['seat' => 3],
            ]);
    }

    public function test_a_stray_string_gets_wrapped_too(): void
    {
        $this->getJson('/api/v1/__env/bare-string')
            ->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('data', 'ok');
    }

    public function test_an_already_wrapped_response_is_left_alone(): void
    {
        // It cannot be wrapped twice: `data.data` would be a different contract.
        $this->getJson('/api/v1/__env/already-wrapped')
            ->assertOk()
            ->assertExactJson([
                'status' => 200,
                'message' => 'Done',
                'error' => null,
                'data' => ['seat' => 3],
            ]);
    }

    public function test_a_null_return_still_produces_the_envelope(): void
    {
        $this->getJson('/api/v1/__env/nothing')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('error', null);
    }

    public function test_a_204_stays_a_204_without_a_body(): void
    {
        $this->getJson('/api/v1/__env/no-content')->assertNoContent();
    }

    public function test_every_api_response_has_the_four_envelope_keys(): void
    {
        foreach (['bare-array', 'bare-string', 'already-wrapped', 'nothing'] as $case) {
            $this->assertSame(
                ['status', 'message', 'error', 'data'],
                array_keys($this->getJson("/api/v1/__env/{$case}")->json()),
                "The route '{$case}' breaks the envelope.",
            );
        }
    }

    public function test_too_many_requests_returns_the_envelope_and_keeps_retry_after(): void
    {
        $this->getJson('/api/v1/__env/limited')->assertOk();
        $this->getJson('/api/v1/__env/limited')->assertOk();

        $response = $this->getJson('/api/v1/__env/limited')->assertStatus(429);

        $response->assertJsonPath('error', 'too_many_requests');

        // Without Retry-After the client does not know how long to wait and
        // retries in a loop.
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_a_business_failure_is_not_written_to_the_log(): void
    {
        // A player playing out of turn is not an incident. If this gets reported,
        // the log fills up with noise and the real failures get lost.
        Log::spy();

        $this->getJson('/api/v1/__env/business')->assertStatus(422);

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }
}
