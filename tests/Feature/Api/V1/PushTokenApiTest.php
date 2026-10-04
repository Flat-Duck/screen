<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\DeviceSession;
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

    public function test_stale_logout_delete_cannot_clear_a_newer_push_registration(): void
    {
        $device = $this->authenticateDevice();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'new-login-token']);

        $this->deleteJson('/api/v1/devices/push-token', ['fcm_token' => 'previous-login-token'])->assertNoContent();

        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id, 'fcm_token' => 'new-login-token']);
    }

    public function test_logout_with_current_fcm_token_still_clears_registration(): void
    {
        $device = $this->authenticateDevice();
        $user = User::factory()->create();
        $session = DeviceSession::factory()->for($device)->for($user)->create();
        $device->forceFill(['user_id' => $user->id])->save();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'token-abc']);

        $this->deleteJson('/api/v1/devices/push-token', [
            'fcm_token' => 'token-abc', 'session_id' => $session->uuid,
        ])->assertNoContent();

        $this->assertDatabaseMissing('device_push_tokens', ['device_id' => $device->id]);
    }

    public function test_delayed_logout_cannot_clear_registration_after_another_user_signed_in(): void
    {
        $device = $this->authenticateDevice();
        $formerUser = User::factory()->create();
        $nextUser = User::factory()->create();
        $session = DeviceSession::factory()->for($device)->for($formerUser)->create();
        $device->forceFill(['user_id' => $nextUser->id])->save();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'same-fcm-token']);

        $this->deleteJson('/api/v1/devices/push-token', [
            'fcm_token' => 'same-fcm-token', 'session_id' => $session->uuid,
        ])->assertNoContent();

        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id, 'fcm_token' => 'same-fcm-token']);
    }

    public function test_account_deletion_can_clear_its_push_token_after_ending_the_session(): void
    {
        $device = $this->authenticateDevice();
        $user = User::factory()->create();
        $session = DeviceSession::factory()->for($device)->for($user)->create([
            'ended_at' => now(),
            'revoked_at' => now(),
        ]);
        $device->forceFill(['user_id' => null])->save();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'deleted-account-token']);

        $this->deleteJson('/api/v1/devices/push-token', [
            'fcm_token' => 'deleted-account-token', 'session_id' => $session->uuid,
        ])->assertNoContent();

        $this->assertDatabaseMissing('device_push_tokens', ['device_id' => $device->id]);
    }

    public function test_delayed_logout_cannot_clear_registration_after_same_user_signed_in_again(): void
    {
        $device = $this->authenticateDevice();
        $user = User::factory()->create();
        $oldSession = DeviceSession::factory()->for($device)->for($user)->create(['ended_at' => now()]);
        DeviceSession::factory()->for($device)->for($user)->create();
        $device->forceFill(['user_id' => $user->id])->save();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'reused-fcm-token']);

        $this->deleteJson('/api/v1/devices/push-token', [
            'fcm_token' => 'reused-fcm-token', 'session_id' => $oldSession->uuid,
        ])->assertNoContent();

        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id, 'fcm_token' => 'reused-fcm-token']);
    }

    public function test_user_session_token_cannot_manage_device_push_registration(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['user:*']);

        $this->putJson('/api/v1/devices/push-token', ['fcm_token' => 'token'])->assertForbidden();
    }
}
