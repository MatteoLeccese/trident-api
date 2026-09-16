<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_health_answers_inside_the_versioned_prefix(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 200,
                'message' => 'OK',
                'error' => null,
                'data' => ['status' => 'ok'],
            ]);
    }

    public function test_there_is_no_unversioned_api_root(): void
    {
        // Everything hangs off /api/v1. An unversioned route is an oversight.
        $this->getJson('/api/health')->assertNotFound();
    }

    public function test_an_unknown_api_route_returns_the_envelope_not_an_html_page(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found')
            ->assertJsonPath('status', 404);
    }
}
