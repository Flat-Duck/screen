<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ], $overrides);
    }

    public function test_registering_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['user' => ['id', 'username'], 'token']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['username' => 'ada']);
    }

    public function test_registering_with_a_duplicate_username_fails_validation(): void
    {
        User::factory()->create(['username' => 'ada']);

        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['username']);
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        $user = User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'password123!',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user' => ['id'], 'token']);
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['login']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** Guards against the Sanctum-principal-confusion gotcha: a Device token must not work here. */
    public function test_a_device_token_cannot_access_user_only_routes(): void
    {
        $device = Device::factory()->create();
        Sanctum::actingAs($device);

        $response = $this->getJson('/api/v1/feed');

        $response->assertForbidden();
    }

    public function test_registering_with_a_device_name_names_the_token(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['device_name' => 'pixel-8']))
            ->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel-8']);
    }

    public function test_registering_without_a_device_name_defaults_the_token_name(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'mobile']);
    }

    public function test_login_with_a_device_name_names_the_token(): void
    {
        User::factory()->create(['username' => 'ada', 'password' => 'password123!']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'ada',
            'password' => 'password123!',
            'device_name' => 'pixel-8',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'pixel-8']);
    }
}
