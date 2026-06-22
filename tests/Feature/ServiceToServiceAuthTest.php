<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ServiceToServiceAuthTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.discord.api_token' => 'correct-secret-token']);
    }

    public function test_auth_middleware_blocks_missing_token()
    {
        $response = $this->postJson('/api/v1/commands/log-check', []);
        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized service access'
        ]);
    }

    public function test_auth_middleware_blocks_invalid_token()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer wrong-token'
        ])->postJson('/api/v1/commands/log-check', []);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized service access'
        ]);
    }

    public function test_auth_middleware_allows_correct_token()
    {
        // When auth is correct, we expect 422 Unprocessable Content due to empty payload, not 401
        $response = $this->withHeaders([
            'Authorization' => 'Bearer correct-secret-token'
        ])->postJson('/api/v1/commands/log-check', []);

        $this->assertNotEquals(401, $response->getStatusCode());
    }
}
