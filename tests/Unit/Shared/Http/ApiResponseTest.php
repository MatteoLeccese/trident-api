<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\Uuid;
use Src\Shared\Domain\ValueObjects\Version;
use Src\Shared\Infrastructure\Http\ApiResponse;

final class ApiResponseTest extends TestCase
{
    private function decode(JsonResponse $response): array
    {
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_success_produces_the_standard_envelope(): void
    {
        $response = ApiResponse::success(['game_id' => 'abc'], 'Partida obtenida');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'status' => 200,
            'message' => 'Partida obtenida',
            'error' => null,
            'data' => ['game_id' => 'abc'],
        ], $this->decode($response));
    }

    public function test_the_envelope_keys_are_always_in_the_same_order(): void
    {
        // The contract with the frontend is a shape, not a set of keys.
        $this->assertSame(
            ['status', 'message', 'error', 'data'],
            array_keys($this->decode(ApiResponse::success())),
        );
    }

    public function test_success_without_data_sends_null_not_an_empty_array(): void
    {
        $body = $this->decode(ApiResponse::success());

        $this->assertNull($body['data']);
        $this->assertNull($body['error']);
    }

    public function test_created_responds_201(): void
    {
        $response = ApiResponse::created(['id' => 1]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(201, $this->decode($response)['status']);
    }

    public function test_error_carries_a_machine_code_and_the_status(): void
    {
        $response = ApiResponse::error('not_your_turn', 'No es tu turno.', 422);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'status' => 422,
            'message' => 'No es tu turno.',
            'error' => 'not_your_turn',
            'data' => null,
        ], $this->decode($response));
    }

    public function test_error_can_carry_data_so_a_stale_client_can_self_heal(): void
    {
        // game_version_conflict returns the current state so the phone can recover on its own.
        $response = ApiResponse::error('game_version_conflict', 'Estado obsoleto.', 422, ['version' => 47]);

        $this->assertSame(['version' => 47], $this->decode($response)['data']);
    }

    public function test_meta_is_absent_unless_explicitly_provided(): void
    {
        $this->assertArrayNotHasKey('meta', $this->decode(ApiResponse::success(['a' => 1])));
    }

    public function test_success_with_meta_appends_meta_after_data(): void
    {
        $response = ApiResponse::successWithMeta(['a' => 1], ['served_at' => '2026-09-15T00:00:00+00:00']);

        $this->assertSame(
            ['status', 'message', 'error', 'data', 'meta'],
            array_keys($this->decode($response)),
        );
        $this->assertSame(['served_at' => '2026-09-15T00:00:00+00:00'], $this->decode($response)['meta']);
    }

    public function test_the_shared_value_objects_serialise_to_their_value(): void
    {
        // Otherwise they travel as `{}` with no error and no warning, on HTTP 200.
        $raw = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $body = $this->decode(ApiResponse::success([
            'game_id' => Uuid::fromString($raw),
            'version' => Version::fromInt(47),
        ]));

        $this->assertSame($raw, $body['data']['game_id']);
        $this->assertSame(47, $body['data']['version']);
    }

    public function test_a_list_is_carried_as_a_json_array_not_an_object(): void
    {
        // The frontend types data as T[]; an empty array cannot serialise as {}.
        $this->assertSame('[]', substr(strstr(ApiResponse::success([])->getContent(), '"data":'), 7, 2));
    }
}
