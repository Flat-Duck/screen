<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SessionEndReason;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_returns_durable_device_sessions_and_flags_the_current_one(): void
    {
        $user = User::factory()->create();
        $current = $this->startUserSession($user, Device::factory()->create(['model' => 'Pixel 8']), 'pixel');
        $other = $this->startUserSession($user, Device::factory()->create(['model' => 'Galaxy S24']), 'galaxy');

        $response = $this->withHeader('Authorization', "Bearer {$current->token}")->getJson('/api/v1/sessions');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data' => [[
            'session_id', 'login_method', 'device', 'started_at', 'last_seen_at',
            'two_factor_verified_at', 'revoked_at', 'status', 'is_revoked', 'is_current',
        ]]]);
        $this->assertTrue(collect($response->json('data'))->firstWhere('session_id', $current->session->uuid)['is_current']);
        $this->assertFalse(collect($response->json('data'))->firstWhere('session_id', $other->session->uuid)['is_current']);
    }

    public function test_listing_never_includes_another_users_sessions(): void
    {
        $user = User::factory()->create();
        $current = $this->startUserSession($user);
        $this->startUserSession(User::factory()->create());

        $this->withHeader('Authorization', "Bearer {$current->token}")
            ->getJson('/api/v1/sessions')
            ->assertJsonCount(1, 'data');
    }

    public function test_remote_revocation_closes_only_the_selected_session(): void
    {
        $user = User::factory()->create();
        $current = $this->startUserSession($user);
        $other = $this->startUserSession($user, Device::factory()->create());

        $this->withHeader('Authorization', "Bearer {$current->token}")
            ->deleteJson("/api/v1/sessions/{$other->session->uuid}")
            ->assertNoContent();

        $this->assertSame(SessionEndReason::Revoked, $other->session->fresh()->end_reason);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->session->personal_access_token_id]);
    }

    public function test_revoking_another_users_session_is_an_idempotent_no_op(): void
    {
        $owner = $this->startUserSession(User::factory()->create());
        $attacker = $this->startUserSession(User::factory()->create());

        $this->withHeader('Authorization', "Bearer {$attacker->token}")
            ->deleteJson("/api/v1/sessions/{$owner->session->uuid}")
            ->assertNoContent();

        $this->assertNull($owner->session->fresh()->ended_at);
    }

    public function test_revoke_others_keeps_the_current_session(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $current = $this->startUserSession($user);
        $other = $this->startUserSession($user, Device::factory()->create());

        $this->withHeader('Authorization', "Bearer {$current->token}")
            ->postJson('/api/v1/sessions/revoke-others', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertNull($current->session->fresh()->ended_at);
        $this->assertSame(SessionEndReason::Revoked, $other->session->fresh()->end_reason);
    }

    public function test_revoke_others_still_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $current = $this->startUserSession($user);
        $this->startUserSession($user, Device::factory()->create());

        $this->withHeader('Authorization', "Bearer {$current->token}")
            ->postJson('/api/v1/sessions/revoke-others', ['current_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }
}
