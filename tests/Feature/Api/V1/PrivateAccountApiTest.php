<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AccountVisibility;
use App\Enums\FollowRequestStatus;
use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateAccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_visibility_can_be_changed_through_settings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/settings', [
            'privacy' => ['account_visibility' => 'private'],
        ])->assertOk()->assertJsonPath('data.privacy.account_visibility', 'private');

        $this->assertSame(AccountVisibility::Private, $user->fresh()->account_visibility);
        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.privacy.account_visibility', 'private');
    }

    public function test_following_a_private_account_creates_a_pending_request(): void
    {
        $requester = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        Sanctum::actingAs($requester);

        $this->postJson("/api/v1/users/{$target->id}/follow")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'requested');

        $this->assertDatabaseMissing('follows', ['follower_id' => $requester->id, 'followee_id' => $target->id]);
        $this->assertDatabaseHas('follow_requests', [
            'requester_id' => $requester->id,
            'target_id' => $target->id,
            'status' => FollowRequestStatus::Pending->value,
        ]);
    }

    public function test_private_account_can_accept_a_request_and_grant_access(): void
    {
        $requester = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        $post = Post::factory()->create(['user_id' => $target->id]);
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'target_id' => $target->id,
            'status' => FollowRequestStatus::Pending,
        ]);

        Sanctum::actingAs($target);
        $this->postJson("/api/v1/follow-requests/{$followRequest->id}/accept")->assertNoContent();

        $this->assertDatabaseHas('follows', ['follower_id' => $requester->id, 'followee_id' => $target->id]);
        $this->assertSame(FollowRequestStatus::Accepted, $followRequest->fresh()->status);

        Sanctum::actingAs($requester);
        $this->getJson("/api/v1/posts/{$post->id}")->assertOk();
        $this->getJson("/api/v1/users/{$target->id}/posts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id);
    }

    public function test_only_the_target_can_accept_a_pending_request(): void
    {
        $requester = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'target_id' => $target->id,
            'status' => FollowRequestStatus::Pending,
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/follow-requests/{$followRequest->id}/accept")->assertNotFound();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_pending_requests_can_be_listed_declined_and_cancelled(): void
    {
        $firstRequester = User::factory()->create();
        $secondRequester = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);

        Sanctum::actingAs($firstRequester);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertStatus(202);
        $this->getJson('/api/v1/follow-requests/outgoing')
            ->assertOk()
            ->assertJsonPath('data.0.target.id', $target->id);

        Sanctum::actingAs($secondRequester);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertStatus(202);
        $this->deleteJson("/api/v1/users/{$target->id}/follow")->assertNoContent();
        $this->assertDatabaseHas('follow_requests', [
            'requester_id' => $secondRequester->id,
            'status' => FollowRequestStatus::Cancelled->value,
        ]);

        Sanctum::actingAs($target);
        $incoming = $this->getJson('/api/v1/follow-requests/incoming')->assertOk();
        $incoming->assertJsonCount(1, 'data');
        $requestId = $incoming->json('data.0.id');
        $this->postJson("/api/v1/follow-requests/{$requestId}/decline")->assertNoContent();
        $this->assertDatabaseHas('follow_requests', ['id' => $requestId, 'status' => FollowRequestStatus::Declined->value]);
    }

    public function test_non_followers_cannot_access_private_content_or_relationship_lists(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        $post = Post::factory()->create(['user_id' => $target->id]);
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.account_visibility', 'private');
        $this->getJson("/api/v1/users/{$target->id}/posts")->assertNotFound();
        $this->getJson("/api/v1/users/{$target->id}/followers")->assertNotFound();
        $this->getJson("/api/v1/users/{$target->id}/following")->assertNotFound();
        $this->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertNotFound();
        $this->postJson("/api/v1/posts/{$post->id}/save")->assertNotFound();
        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNotFound();
    }

    public function test_private_posts_never_appear_in_explore_even_for_followers(): void
    {
        Redis::shouldReceive('zrevrange')->once()->andReturnUsing(
            fn (string $key, int $start, int $stop): array => [$this->privatePostId],
        );

        $viewer = User::factory()->create();
        $target = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        $viewer->following()->attach($target->id);
        $post = Post::factory()->create(['user_id' => $target->id]);
        $this->privatePostId = (string) $post->id;
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/explore')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_blocking_clears_pending_follow_requests_in_both_directions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        FollowRequest::query()->create([
            'requester_id' => $user->id,
            'target_id' => $other->id,
            'status' => FollowRequestStatus::Pending,
        ]);
        FollowRequest::query()->create([
            'requester_id' => $other->id,
            'target_id' => $user->id,
            'status' => FollowRequestStatus::Pending,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$other->id}/block")->assertNoContent();
        $this->assertDatabaseCount('follow_requests', 0);
    }

    private string $privatePostId;
}
