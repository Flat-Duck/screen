<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostLikedNotification;
use App\Services\Fcm\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function withHeaderFor(User $user): self
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_showing_settings_returns_defaults_when_none_are_set(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaderFor($user)->getJson('/api/v1/settings');

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'notifications' => ['likes' => true, 'comments' => true, 'follows' => true],
            ],
        ]);
    }

    public function test_updating_one_notification_key_leaves_the_others_at_their_default(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaderFor($user)
            ->patchJson('/api/v1/settings', ['notifications' => ['likes' => false]]);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'notifications' => ['likes' => false, 'comments' => true, 'follows' => true],
            ],
        ]);
    }

    public function test_updating_persists_across_requests(): void
    {
        $user = User::factory()->create();

        $this->withHeaderFor($user)->patchJson('/api/v1/settings', ['notifications' => ['comments' => false]]);

        $response = $this->withHeaderFor($user)->getJson('/api/v1/settings');

        $response->assertJsonPath('data.notifications.comments', false);
        $response->assertJsonPath('data.notifications.likes', true);
    }

    public function test_rejects_a_non_boolean_notification_value(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaderFor($user)
            ->patchJson('/api/v1/settings', ['notifications' => ['likes' => 'not-a-bool']]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['notifications.likes']);
    }

    public function test_disabling_a_notification_type_skips_the_push_send_but_not_the_database_record(): void
    {
        $recipient = User::factory()->create();
        $recipient->settings = ['notifications' => ['likes' => false]];
        $recipient->save();
        $device = Device::factory()->for($recipient)->create();
        DevicePushToken::factory()->for($device)->create();

        $liker = User::factory()->create();
        $post = Post::factory()->for($recipient)->create();

        $fcm = Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->andReturn(true);
        $fcm->shouldNotReceive('send');
        $this->app->instance(FcmClient::class, $fcm);

        $recipient->notify(new PostLikedNotification($post, $liker));

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_leaving_a_notification_type_enabled_still_sends_the_push(): void
    {
        $recipient = User::factory()->create();
        $device = Device::factory()->for($recipient)->create();
        DevicePushToken::factory()->for($device)->create(['fcm_token' => 'token-abc']);

        $liker = User::factory()->create();
        $post = Post::factory()->for($recipient)->create();

        $fcm = Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->andReturn(true);
        $fcm->shouldReceive('send')->once()->with('token-abc', Mockery::any(), Mockery::any(), Mockery::any())->andReturn('ok');
        $this->app->instance(FcmClient::class, $fcm);

        $recipient->notify(new PostLikedNotification($post, $liker));
    }
}
