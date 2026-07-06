<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorResponseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_resource_returns_a_404_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/posts/999999');

        $response->assertNotFound();
        $response->assertJsonStructure(['message']);
    }

    public function test_invalid_input_returns_a_422_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', []);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['message', 'errors' => ['images']]);
    }

    public function test_unauthenticated_request_returns_a_401_shape(): void
    {
        $response = $this->getJson('/api/v1/feed');

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_device_token_on_a_user_only_route_returns_a_403_shape(): void
    {
        Sanctum::actingAs(Device::factory()->create());

        $response = $this->getJson('/api/v1/feed');

        $response->assertForbidden();
        $response->assertJsonStructure(['message']);
    }
}
