<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Src\Shared\Domain\Exceptions\BusinessException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class ExceptionRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/__test')->group(function (): void {
            Route::get('/business', fn () => throw new BusinessException('not_your_turn', 'It is not your turn.'));
            Route::get('/business-with-data', fn () => throw new BusinessException(
                'game_version_conflict',
                'That state is out of date.',
                422,
                ['version' => 47],
            ));
            Route::get('/business-404', fn () => throw new BusinessException('game_not_found', 'That does not exist.', 404));
            Route::get('/validation', fn () => throw ValidationException::withMessages([
                'nickname' => ['That name is already taken.'],
                'seat' => ['That seat is not valid.'],
            ]));
            Route::get('/model-missing', fn () => throw new ModelNotFoundException);
            Route::get('/forbidden', fn () => throw new AuthorizationException);
            Route::get('/unauthenticated', fn () => throw new AuthenticationException);
            Route::get('/boom', fn () => throw new RuntimeException('internal detail that must not leak'));
            Route::get('/abort-409', fn () => abort(409, 'Raw Laravel conflict'));
            Route::get('/abort-403', fn () => abort(403));
            Route::get('/abort-503', fn () => abort(503));
            Route::post('/solo-post', fn () => 'ok');
            Route::get('/denied-directly', fn () => throw new AccessDeniedHttpException);
            Route::get('/missing-directly', fn () => throw new NotFoundHttpException);
            Route::get('/query-ex', fn () => throw new QueryException(
                'pgsql',
                'select * from games where controller_token_hash = ?',
                ['SECRET-SENTINEL'],
                new RuntimeException('SQLSTATE[08006] password authentication failed for user "trident"'),
            ));
        });
    }

    public function test_a_business_failure_is_a_422_carrying_its_machine_code(): void
    {
        $this->getJson('/api/v1/__test/business')
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 422,
                'message' => 'It is not your turn.',
                'error' => 'not_your_turn',
                'data' => null,
            ]);
    }

    public function test_a_business_failure_can_carry_recovery_data(): void
    {
        $this->getJson('/api/v1/__test/business-with-data')
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_version_conflict')
            ->assertJsonPath('data.version', 47);
    }

    public function test_a_business_failure_chooses_its_own_status(): void
    {
        $this->getJson('/api/v1/__test/business-404')
            ->assertStatus(404)
            ->assertJsonPath('error', 'game_not_found')
            ->assertJsonPath('status', 404);
    }

    public function test_validation_is_422_validation_error_with_only_the_first_message(): void
    {
        $this->getJson('/api/v1/__test/validation')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error')
            ->assertJsonPath('message', 'That name is already taken.');
    }

    public function test_a_missing_model_is_404_not_found(): void
    {
        $this->getJson('/api/v1/__test/model-missing')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_an_authorization_failure_is_403_forbidden(): void
    {
        $this->getJson('/api/v1/__test/forbidden')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_an_authentication_failure_is_401_unauthenticated(): void
    {
        // Laravel ships its own unauthenticated() that returns {"message": "..."}
        // and would bypass the envelope. This test is what prevents that regression.
        $this->getJson('/api/v1/__test/unauthenticated')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated')
            ->assertJsonPath('status', 401);
    }

    public function test_debug_mode_adds_diagnostics_without_changing_the_envelope(): void
    {
        config()->set('app.debug', true);

        $body = $this->getJson('/api/v1/__test/boom')->assertStatus(500)->json();

        $this->assertSame(['status', 'message', 'error', 'data'], array_keys($body));
        $this->assertSame('internal_error', $body['error']);
        $this->assertArrayHasKey('debug', $body['data']);
    }

    public function test_an_unexpected_error_is_500_internal_error_and_leaks_nothing(): void
    {
        config()->set('app.debug', false);

        $response = $this->getJson('/api/v1/__test/boom')->assertStatus(500);

        $response->assertJsonPath('error', 'internal_error');
        $this->assertStringNotContainsString('internal detail', $response->getContent());
        $this->assertStringNotContainsString('vendor/', $response->getContent());
    }

    public function test_a_method_not_allowed_is_405_not_500(): void
    {
        // A phone on an old build calls with the old verb. If that arrives as a 500,
        // the television says "unexpected error" and the table is left stuck.
        $this->getJson('/api/v1/__test/solo-post')
            ->assertStatus(405)
            ->assertJsonPath('error', 'method_not_allowed');
    }

    public function test_a_bare_abort_keeps_its_status_and_gets_a_machine_code(): void
    {
        $this->getJson('/api/v1/__test/abort-409')
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict');

        $this->getJson('/api/v1/__test/abort-403')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->getJson('/api/v1/__test/abort-503')
            ->assertStatus(503)
            ->assertJsonPath('error', 'service_unavailable');
    }

    public function test_an_abort_never_echoes_its_own_message_to_the_client(): void
    {
        // abort(409, '...') leaves developer text in the exception.
        $this->getJson('/api/v1/__test/abort-409')
            ->assertStatus(409)
            ->assertDontSee('Raw Laravel conflict');
    }

    public function test_the_http_exceptions_laravel_actually_produces_are_covered_directly(): void
    {
        // Laravel converts AuthorizationException and ModelNotFoundException before
        // the callbacks, so these are the types that actually get rendered.
        $this->getJson('/api/v1/__test/denied-directly')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->getJson('/api/v1/__test/missing-directly')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_a_database_failure_never_leaks_the_query_or_the_credentials(): void
    {
        // With debug ON, which is the only state this project runs in.
        config()->set('app.debug', true);

        $response = $this->getJson('/api/v1/__test/query-ex')->assertStatus(500);

        $response->assertJsonPath('error', 'database_error');
        $this->assertStringNotContainsString('SECRET-SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('controller_token_hash', $response->getContent());
        $this->assertStringNotContainsString('password authentication', $response->getContent());
    }

    public function test_a_database_failure_never_writes_its_bindings_to_the_log(): void
    {
        // `QueryException` builds its message by interpolating the bindings into
        // the statement, and every write of a game binds `games.shuffle_seed`.
        // Logging the exception object therefore puts the one secret of the
        // system (TR-09) in cleartext in storage/logs, where the body that the
        // client never gets would have been useless anyway.
        Log::spy();

        $this->getJson('/api/v1/__test/query-ex')->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                // Once, and this once: the default reporter logs the exception's
                // own message, which is the statement with its bindings in it.
                $logged = $message.' '.(string) json_encode($context);

                $this->assertStringNotContainsString('SECRET-SENTINEL', $logged);
                $this->assertStringContainsString('controller_token_hash = ?', $logged, 'The statement is still there.');

                return true;
            });
    }

    public function test_debug_mode_never_puts_the_exception_message_on_the_wire(): void
    {
        config()->set('app.debug', true);

        $response = $this->getJson('/api/v1/__test/boom')->assertStatus(500);

        $this->assertStringNotContainsString('internal detail', $response->getContent());
    }

    public function test_an_unexpected_error_hands_back_a_reference_to_find_it_in_the_log(): void
    {
        config()->set('app.debug', true);

        $this->getJson('/api/v1/__test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error', 'internal_error')
            ->assertJsonStructure(['data' => ['ref']]);
    }

    public function test_every_error_response_keeps_the_same_envelope_shape(): void
    {
        foreach (['business', 'validation', 'model-missing', 'forbidden', 'unauthenticated'] as $case) {
            $body = $this->getJson("/api/v1/__test/{$case}")->json();

            $this->assertSame(
                ['status', 'message', 'error', 'data'],
                array_keys($body),
                "The route '{$case}' breaks the envelope.",
            );
            $this->assertIsString($body['error']);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $body['error']);
        }
    }
}
