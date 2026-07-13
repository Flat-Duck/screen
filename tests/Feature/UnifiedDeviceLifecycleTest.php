<?php

namespace Tests\Feature;

use App\Actions\Accounts\PurgeDeletedAccount;
use App\Actions\Auth\ExpireDeviceSessions;
use App\Actions\Telemetry\PruneTelemetry;
use App\Enums\SessionEndReason;
use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\NewFollowerNotification;
use App\Services\AccountService;
use App\Services\Fcm\FcmClient;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UnifiedDeviceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_switch_replaces_session_and_redirects_push_delivery(): void
    {
        $device = Device::factory()->create();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'device-token']);
        $firstUser = User::factory()->create();
        $first = $this->startUserSession($firstUser, $device);
        $secondUser = User::factory()->create();
        $second = $this->startUserSession($secondUser, $device);

        $this->assertSame(SessionEndReason::Replaced, $first->session->fresh()->end_reason);
        $this->assertNull($first->session->fresh()->personal_access_token_id);
        $this->assertNull($second->session->fresh()->ended_at);
        $this->assertSame($secondUser->id, $device->fresh()->user_id);

        $fcm = Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->with('device-token', Mockery::any(), Mockery::any(), Mockery::any())->andReturn('ok');
        $channel = new FcmChannel($fcm, app(SettingsService::class));
        $notification = new NewFollowerNotification(User::factory()->create());

        $channel->send($firstUser, $notification);
        $channel->send($secondUser, $notification);
    }

    public function test_logout_clears_current_user_but_keeps_dormant_fcm_registration(): void
    {
        $device = Device::factory()->create();
        DevicePushToken::factory()->for($device)->create();
        $issued = $this->startUserSession(User::factory()->create(), $device);

        $this->withHeader('Authorization', "Bearer {$issued->token}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertNull($device->fresh()->user_id);
        $this->assertSame(SessionEndReason::Logout, $issued->session->fresh()->end_reason);
        $this->assertDatabaseHas('device_push_tokens', ['device_id' => $device->id]);
    }

    public function test_expiration_closes_session_and_clears_device_assignment(): void
    {
        $device = Device::factory()->create();
        $issued = $this->startUserSession(User::factory()->create(), $device);
        $issued->session->accessToken()->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(ExpireDeviceSessions::class)());
        $this->assertSame(SessionEndReason::Expired, $issued->session->fresh()->end_reason);
        $this->assertNull($device->fresh()->user_id);
    }

    public function test_soft_delete_retains_attribution_but_final_purge_anonymizes_crash(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();
        $session = $this->startUserSession($user, $device)->session;
        $event = TelemetryEvent::factory()->fatalCrash()->create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'device_session_id' => $session->id,
        ]);

        app(AccountService::class)->deleteAccount($user);

        $this->assertSame($user->id, $event->fresh()->user_id);
        $this->assertSame($session->id, $event->fresh()->device_session_id);
        $this->assertNull($device->fresh()->user_id);

        $user->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        app(PurgeDeletedAccount::class)($user->id);

        $this->assertNull($event->fresh()->user_id);
        $this->assertNull($event->fresh()->device_session_id);
        $this->assertDatabaseHas('telemetry_events', ['id' => $event->id]);
    }

    public function test_telemetry_retention_prunes_only_expired_events(): void
    {
        $expired = TelemetryEvent::factory()->create(['received_at' => now()->subDays(91)]);
        $current = TelemetryEvent::factory()->create(['received_at' => now()->subDays(89)]);

        $this->assertSame(1, app(PruneTelemetry::class)(now()->subDays(90)));
        $this->assertDatabaseMissing('telemetry_events', ['id' => $expired->id]);
        $this->assertDatabaseHas('telemetry_events', ['id' => $current->id]);
    }
}
