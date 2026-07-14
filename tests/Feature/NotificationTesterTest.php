<?php

namespace Tests\Feature;

use App\Livewire\NotificationTester;
use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewFollowerNotification;
use App\Notifications\PostCommentedNotification;
use App\Notifications\PostLikedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('notifications.index'))->assertForbidden();
    }

    public function test_admin_users_can_view_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->get(route('notifications.index'));

        $response->assertOk();
        $response->assertSee('Send a push notification');
        $response->assertSee('Send a notification');
    }

    public function test_sending_a_push_reports_when_fcm_is_not_configured(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $recipient = User::factory()->create();

        Livewire::test(NotificationTester::class)
            ->set('pushTarget', 'user')
            ->set('pushUserId', $recipient->id)
            ->set('pushTitle', 'Hello')
            ->set('pushBody', 'World')
            ->call('sendPush')
            ->assertSet('pushResult', 'FCM is not configured on this environment (FIREBASE_PROJECT_ID / FIREBASE_CREDENTIALS_PATH).');
    }

    public function test_sending_a_push_to_a_user_reaches_every_one_of_their_devices(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->configureFcm();

        $recipient = User::factory()->create();
        $deviceA = Device::factory()->for($recipient)->create();
        $deviceB = Device::factory()->for($recipient)->create();
        DevicePushToken::factory()->for($deviceA)->create(['fcm_token' => 'token-a']);
        DevicePushToken::factory()->for($deviceB)->create(['fcm_token' => 'token-b']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-project/messages/1']),
        ]);

        Livewire::test(NotificationTester::class)
            ->set('pushTarget', 'user')
            ->set('pushUserId', $recipient->id)
            ->set('pushTitle', 'Hello')
            ->set('pushBody', 'World')
            ->set('pushImageUrl', 'https://example.com/banner.png')
            ->call('sendPush')
            ->assertSet('pushResult', 'Sent to 2 device(s).');

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'fcm.googleapis.com')
            && $request['message']['notification']['image'] === 'https://example.com/banner.png');
    }

    public function test_sending_a_push_requires_a_target_selection(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(NotificationTester::class)
            ->set('pushTarget', 'user')
            ->set('pushTitle', 'Hello')
            ->set('pushBody', 'World')
            ->call('sendPush')
            ->assertHasErrors(['pushUserId']);
    }

    public function test_sending_a_follow_notification_dispatches_the_real_notification_class(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Notification::fake();

        $recipient = User::factory()->create();
        $follower = User::factory()->create();

        Livewire::test(NotificationTester::class)
            ->set('notifRecipientId', $recipient->id)
            ->set('notifType', 'follow')
            ->set('notifActorId', $follower->id)
            ->call('sendTestNotification');

        Notification::assertSentTo($recipient, NewFollowerNotification::class);
    }

    public function test_sending_a_like_notification_requires_a_post(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $recipient = User::factory()->create();
        $liker = User::factory()->create();

        Livewire::test(NotificationTester::class)
            ->set('notifRecipientId', $recipient->id)
            ->set('notifType', 'like')
            ->set('notifActorId', $liker->id)
            ->call('sendTestNotification')
            ->assertHasErrors(['notifPostId']);
    }

    public function test_sending_a_like_notification_dispatches_the_real_notification_class(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Notification::fake();

        $recipient = User::factory()->create();
        $liker = User::factory()->create();
        $post = Post::factory()->for($recipient)->create();

        Livewire::test(NotificationTester::class)
            ->set('notifRecipientId', $recipient->id)
            ->set('notifType', 'like')
            ->set('notifActorId', $liker->id)
            ->set('notifPostId', $post->id)
            ->call('sendTestNotification');

        Notification::assertSentTo($recipient, PostLikedNotification::class);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_sending_a_comment_notification_never_persists_a_real_comment(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Notification::fake();

        $recipient = User::factory()->create();
        $commenter = User::factory()->create();
        $post = Post::factory()->for($recipient)->create();

        Livewire::test(NotificationTester::class)
            ->set('notifRecipientId', $recipient->id)
            ->set('notifType', 'comment')
            ->set('notifActorId', $commenter->id)
            ->set('notifPostId', $post->id)
            ->set('notifCommentBody', 'A test comment')
            ->call('sendTestNotification');

        Notification::assertSentTo($recipient, PostCommentedNotification::class, function (PostCommentedNotification $notification) use ($recipient): bool {
            $data = $notification->toArray($recipient);

            return $data['excerpt'] === 'A test comment';
        });
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_the_actor_must_differ_from_the_recipient(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $user = User::factory()->create();

        Livewire::test(NotificationTester::class)
            ->set('notifRecipientId', $user->id)
            ->set('notifType', 'follow')
            ->set('notifActorId', $user->id)
            ->call('sendTestNotification')
            ->assertHasErrors(['notifActorId']);
    }

    private function configureFcm(): void
    {
        config([
            'services.fcm.project_id' => 'demo-project',
            'services.fcm.credentials_path' => $this->fakeCredentialsFile(),
        ]);
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
