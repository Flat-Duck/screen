<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_login_device_can_register_its_push_token(): void
    {
        $device = $this->authenticateDevice();

        $this->putJson('/api/v1/devices/push-token', ['fcm_token' => 'token-abc'])->assertNoContent();

        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id, 'fcm_token' => 'token-abc']);
    }

    public function test_rotating_a_token_keeps_one_row_for_the_device(): void
    {
        $device = $this->authenticateDevice();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'old-token']);

        $this->putJson('/api/v1/devices/push-token', ['fcm_token' => 'new-token'])->assertNoContent();

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id, 'fcm_token' => 'new-token']);
    }

    public function test_a_global_fcm_token_moves_to_the_current_installation(): void
    {
        $oldDevice = Device::factory()->create();
        DevicePushToken::factory()->for($oldDevice)->create(['fcm_token' => 'shared-token']);
        $newDevice = $this->authenticateDevice();

        $this->putJson('/api/v1/devices/push-token', ['fcm_token' => 'shared-token'])->assertNoContent();

        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $newDevice->id, 'fcm_token' => 'shared-token']);
    }

    public function test_deleting_push_registration_clears_only_the_authenticated_device(): void
    {
        $device = $this->authenticateDevice();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'token-abc']);
        $other = Device::factory()->create();
        DevicePushToken::factory()->for($other)->create(['fcm_token' => 'token-xyz']);

        $this->deleteJson('/api/v1/devices/push-token')->assertNoContent();

        $this->assertDatabaseMissing('device_push_tokens', ['device_id' => $device->id]);
        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $other->id]);
    }

    public function test_user_session_token_cannot_manage_device_push_registration(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['user:*']);

        $this->putJson('/api/v1/devices/push-token', ['fcm_token' => 'token'])->assertForbidden();
    }
}
