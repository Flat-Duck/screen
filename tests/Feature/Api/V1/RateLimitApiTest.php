<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_route_is_throttled_by_ip(): void
    {
        $this->authenticateDevice();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'wrong']);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertJson(['message' => 'Too Many Attempts.']);
    }

    public function test_authenticated_route_is_throttled_per_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->patchJson('/api/v1/profile', [])->assertOk();
        }

        $response = $this->patchJson('/api/v1/profile', []);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertJson(['message' => 'Too Many Attempts.']);
    }
}
