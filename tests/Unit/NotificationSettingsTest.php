<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\SecurityFcmNotification;
use App\Services\Fcm\FcmClient;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_and_category_toggles_disable_push(): void
    {
        $user = User::factory()->create();
        $service = app(SettingsService::class);

        $user->settings = ['notifications' => ['push_enabled' => false]];
        $user->save();
        $this->assertFalse($service->pushNotificationsEnabledFor($user, 'likes'));

        $user->settings = ['notifications' => ['push_enabled' => true, 'likes' => false]];
        $user->save();
        $this->assertFalse($service->pushNotificationsEnabledFor($user, 'likes'));
        $this->assertTrue($service->pushNotificationsEnabledFor($user, 'comments'));
    }

    public function test_overnight_quiet_hours_use_the_users_timezone_and_boundaries(): void
    {
        $user = User::factory()->create();
        $user->settings = ['notifications' => ['quiet_hours' => [
            'enabled' => true,
            'start' => '22:00',
            'end' => '07:00',
            'timezone' => 'Africa/Tripoli',
        ]]];
        $user->save();
        $service = app(SettingsService::class);

        $this->assertFalse($service->pushNotificationsEnabledFor($user, 'messages', Carbon::parse('2026-07-20 20:00:00', 'UTC')));
        $this->assertFalse($service->pushNotificationsEnabledFor($user, 'messages', Carbon::parse('2026-07-21 04:59:59', 'UTC')));
        $this->assertTrue($service->pushNotificationsEnabledFor($user, 'messages', Carbon::parse('2026-07-21 05:00:00', 'UTC')));
    }

    public function test_security_push_bypasses_social_preferences_and_quiet_hours(): void
    {
        $user = User::factory()->create();
        $user->settings = ['notifications' => ['push_enabled' => false]];
        $user->save();
        $device = Device::factory()->for($user)->create();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'security-token']);

        $fcm = Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->with('security-token', 'Security alert', 'Account changed', [])->andReturn('ok');

        $notification = new class extends Notification implements SecurityFcmNotification
        {
            /** @return array{title: string, body: string, data: array<string, string>} */
            public function toFcm(object $notifiable): array
            {
                return ['title' => 'Security alert', 'body' => 'Account changed', 'data' => []];
            }

            public function settingsKey(): string
            {
                return 'security';
            }
        };

        (new FcmChannel($fcm, app(SettingsService::class)))->send($user, $notification);
    }
}
