<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use App\Notifications\NewFollowerNotification;
use App\Notifications\PostCommentedNotification;
use App\Notifications\PostLikedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_following_someone_else_notifies_them(): void
    {
        Notification::fake();

        $follower = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($follower);

        $this->postJson("/api/v1/users/{$target->id}/follow")->assertNoContent();

        Notification::assertSentTo($target, NewFollowerNotification::class);
    }

    public function test_liking_someone_elses_post_notifies_the_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);
        $liker = User::factory()->create();
        Sanctum::actingAs($liker);

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();

        Notification::assertSentTo($owner, PostLikedNotification::class);
    }

    public function test_liking_your_own_post_does_not_notify_you(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();

        Notification::assertNothingSent();
    }

    public function test_commenting_on_someone_elses_post_notifies_the_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);
        $commenter = User::factory()->create();
        Sanctum::actingAs($commenter);

        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Nice!'])->assertCreated();

        Notification::assertSentTo($owner, PostCommentedNotification::class);
    }

    public function test_commenting_on_your_own_post_does_not_notify_you(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Nice!'])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_index_only_returns_the_authenticated_users_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $follower = User::factory()->create();

        $user->notify(new NewFollowerNotification($follower));
        $other->notify(new NewFollowerNotification($follower));

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']], 'links', 'meta']);
        $response->assertJsonPath('data.0.type', 'new_follower');
    }

    public function test_marking_a_notification_read_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->notify(new NewFollowerNotification(User::factory()->create()));
        $notificationId = $user->notifications()->firstOrFail()->id;

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/notifications/{$notificationId}/read")->assertNoContent();
        $this->assertNotNull($user->notifications()->firstOrFail()->read_at);

        $response = $this->patchJson("/api/v1/notifications/{$notificationId}/read");
        $response->assertNoContent();
    }

    public function test_marking_another_users_notification_read_404s(): void
    {
        $owner = User::factory()->create();
        $owner->notify(new NewFollowerNotification(User::factory()->create()));
        $notificationId = $owner->notifications()->firstOrFail()->id;

        Sanctum::actingAs(User::factory()->create());

        $response = $this->patchJson("/api/v1/notifications/{$notificationId}/read");

        $response->assertNotFound();
    }

    public function test_mark_all_read_flips_every_unread_notification(): void
    {
        $user = User::factory()->create();
        $user->notify(new NewFollowerNotification(User::factory()->create()));
        $user->notify(new NewFollowerNotification(User::factory()->create()));

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/notifications/read-all');

        $response->assertNoContent();
        $this->assertSame(0, $user->unreadNotifications()->count());
    }
}
