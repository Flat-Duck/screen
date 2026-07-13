<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Notifications\NewFollowerNotification;
use App\Services\Fcm\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fcm_client_is_not_configured_by_default(): void
    {
        $this->assertFalse(app(FcmClient::class)->isConfigured());
    }

    public function test_fcm_client_is_configured_once_project_id_and_a_readable_credentials_file_are_set(): void
    {
        $path = $this->fakeCredentialsFile();
        config(['services.fcm.project_id' => 'demo-project', 'services.fcm.credentials_path' => $path]);

        $this->assertTrue(app(FcmClient::class)->isConfigured());
    }

    public function test_notification_delivery_is_skipped_silently_when_fcm_is_not_configured(): void
    {
        // No config('services.fcm.*') set — default test env state.
        $user = User::factory()->create();
        $this->pushTokenFor($user, 'token-abc');

        Http::fake();

        $user->notify(new NewFollowerNotification(User::factory()->create()));

        Http::assertNothingSent();
    }

    public function test_notification_delivery_is_skipped_when_the_user_has_no_registered_devices(): void
    {
        $this->configureFcm();

        $user = User::factory()->create();

        Http::fake();

        $user->notify(new NewFollowerNotification(User::factory()->create()));

        Http::assertNothingSent();
    }

    public function test_a_notification_sends_a_push_to_every_registered_device(): void
    {
        $this->configureFcm();

        $user = User::factory()->create();
        $this->pushTokenFor($user, 'token-1');
        $this->pushTokenFor($user, 'token-2');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1']),
        ]);

        $follower = User::factory()->create(['name' => 'Ada Lovelace']);
        $user->notify(new NewFollowerNotification($follower));

        Http::assertSentCount(3); // 1 OAuth token exchange + 2 pushes (one per token)
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'oauth2.googleapis.com'));
        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), 'fcm.googleapis.com')
                && $request['message']['token'] === 'token-1'
                && $request['message']['notification']['title'] === 'New follower';
        });
    }

    public function test_an_unregistered_token_is_pruned_after_a_failed_send(): void
    {
        $this->configureFcm();

        $user = User::factory()->create();
        $token = $this->pushTokenFor($user, 'stale-token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);

        $user->notify(new NewFollowerNotification(User::factory()->create()));

        $this->assertDatabaseMissing('device_push_tokens', ['id' => $token->id]);
    }

    public function test_a_transient_send_failure_does_not_prune_the_token(): void
    {
        $this->configureFcm();

        $user = User::factory()->create();
        $token = $this->pushTokenFor($user, 'good-token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $user->notify(new NewFollowerNotification(User::factory()->create()));

        $this->assertDatabaseHas('device_push_tokens', ['id' => $token->id]);
    }

    private function configureFcm(): void
    {
        config([
            'services.fcm.project_id' => 'demo-project',
            'services.fcm.credentials_path' => $this->fakeCredentialsFile(),
        ]);
    }

    private function pushTokenFor(User $user, string $token): DevicePushToken
    {
        $device = Device::factory()->for($user)->create();

        return DevicePushToken::factory()->for($device)->create(['fcm_token' => $token]);
    }

    private function fakeCredentialsFile(): string
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        $path = tempnam(sys_get_temp_dir(), 'fcm-creds').'.json';
        file_put_contents($path, json_encode([
            'client_email' => 'test@demo-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]));

        return $path;
    }
}
