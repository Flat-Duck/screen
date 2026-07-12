<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_sessions_returns_every_token_with_the_current_one_flagged(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('Pixel 8, Android 15')->plainTextToken;
        $user->createToken('Galaxy S24, Android 14');

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/sessions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [['id', 'device_name', 'last_used_at', 'created_at', 'is_current']],
        ]);

        $current = collect($response->json('data'))->firstWhere('device_name', 'Pixel 8, Android 15');
        $other = collect($response->json('data'))->firstWhere('device_name', 'Galaxy S24, Android 14');

        $this->assertTrue($current['is_current']);
        $this->assertFalse($other['is_current']);
    }

    public function test_listing_sessions_never_includes_another_users_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $stranger = User::factory()->create();
        $stranger->createToken('strangers-phone');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sessions');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_revoking_a_session_deletes_only_that_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenBId = $user->createToken('device-b')->accessToken->id;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->deleteJson("/api/v1/sessions/{$tokenBId}")
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenBId]);
    }

    public function test_revoking_someone_elses_session_by_guessing_the_id_does_nothing(): void
    {
        $owner = User::factory()->create();
        $ownerTokenId = $owner->createToken('owners-phone')->accessToken->id;

        $attacker = User::factory()->create();
        $attackerToken = $attacker->createToken('attackers-phone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$attackerToken}")
            ->deleteJson("/api/v1/sessions/{$ownerTokenId}")
            ->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $ownerTokenId]);
    }

    public function test_revoking_a_nonexistent_session_is_a_silent_no_op(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/sessions/999999')
            ->assertNoContent();
    }

    public function test_revoke_others_keeps_only_the_current_session(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');
        $user->createToken('device-c');

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/sessions/revoke-others', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/sessions');
        $response->assertJsonCount(1, 'data');
    }

    public function test_revoke_others_requires_the_current_password_when_one_is_set(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $token = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sessions/revoke-others', ['current_password' => 'wrong-password']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_revoke_others_does_not_require_a_password_for_a_social_only_account(): void
    {
        $user = User::factory()->create(['password' => null]);
        $token = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sessions/revoke-others')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
