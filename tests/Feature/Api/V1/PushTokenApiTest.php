<?php

namespace Tests\Feature\Api\V1;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_push_token_creates_a_row_for_the_current_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices/push-token', ['fcm_token' => 'token-abc']);

        $response->assertNoContent();
        $this->assertDatabaseHas('device_push_tokens', ['user_id' => $user->id, 'fcm_token' => 'token-abc']);
    }

    public function test_registering_the_same_token_again_re_points_it_to_the_new_owner(): void
    {
        $previousOwner = User::factory()->create();
        DevicePushToken::create(['user_id' => $previousOwner->id, 'fcm_token' => 'token-abc']);

        $newOwner = User::factory()->create();
        Sanctum::actingAs($newOwner);

        $this->postJson('/api/v1/devices/push-token', ['fcm_token' => 'token-abc'])->assertNoContent();

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', ['user_id' => $newOwner->id, 'fcm_token' => 'token-abc']);
    }

    public function test_deleting_a_push_token_removes_only_the_current_users_row(): void
    {
        $user = User::factory()->create();
        DevicePushToken::create(['user_id' => $user->id, 'fcm_token' => 'token-abc']);

        $otherUser = User::factory()->create();
        DevicePushToken::create(['user_id' => $otherUser->id, 'fcm_token' => 'token-xyz']);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/devices/push-token', ['fcm_token' => 'token-abc']);

        $response->assertNoContent();
        $this->assertDatabaseMissing('device_push_tokens', ['fcm_token' => 'token-abc']);
        $this->assertDatabaseHas('device_push_tokens', ['fcm_token' => 'token-xyz']);
    }

    public function test_deleting_someone_elses_token_by_guessing_its_value_does_nothing(): void
    {
        $owner = User::factory()->create();
        DevicePushToken::create(['user_id' => $owner->id, 'fcm_token' => 'token-abc']);

        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);

        $this->deleteJson('/api/v1/devices/push-token', ['fcm_token' => 'token-abc'])->assertNoContent();

        $this->assertDatabaseHas('device_push_tokens', ['fcm_token' => 'token-abc', 'user_id' => $owner->id]);
    }
}
