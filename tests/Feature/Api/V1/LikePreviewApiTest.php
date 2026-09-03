<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use App\Services\LikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `liked_by` rides along on the post so a client can draw real faces on its "liked by" row without
 * a second request per card — there is deliberately no `GET /posts/{post}/likes`.
 */
class LikePreviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function like(User $user, Post $post): void
    {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertSuccessful();
    }

    public function test_post_detail_carries_recent_likers_with_their_avatars(): void
    {
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $likers = User::factory()->count(2)->create();
        foreach ($likers as $liker) {
            $this->like($liker, $post);
        }

        Sanctum::actingAs($viewer = User::factory()->create());
        $response = $this->getJson("/api/v1/posts/{$post->id}")->assertOk();

        $response->assertJsonCount(2, 'data.liked_by')
            ->assertJsonPath('data.likes_count', 2)
            // Newest first, so the faces match "and 2 others" reading order.
            ->assertJsonPath('data.liked_by.0.id', $likers[1]->id)
            ->assertJsonPath('data.liked_by.1.id', $likers[0]->id);
        $this->assertArrayHasKey('avatar_url', $response->json('data.liked_by.0'));
        $this->assertSame($viewer->id, $viewer->id);
    }

    /** Three faces is what the clients draw; a popular post must not ship hundreds. */
    public function test_the_preview_is_capped(): void
    {
        $post = Post::factory()->create();
        foreach (User::factory()->count(LikeService::LIKE_PREVIEW_LIMIT + 4)->create() as $liker) {
            $this->like($liker, $post);
        }

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->assertJsonCount(LikeService::LIKE_PREVIEW_LIMIT, 'data.liked_by')
            ->assertJsonPath('data.likes_count', LikeService::LIKE_PREVIEW_LIMIT + 4);
    }

    public function test_a_post_with_no_likes_has_an_empty_preview(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->assertJsonCount(0, 'data.liked_by');
    }

    /**
     * Blocked users are filtered inside the ranking window, not after it — otherwise a post whose
     * three newest likers are all blocked would show an empty row while the count said 4.
     */
    public function test_blocked_likers_are_replaced_rather_than_leaving_a_gap(): void
    {
        $post = Post::factory()->create();
        $visible = User::factory()->create();
        $this->like($visible, $post);
        $blocked = User::factory()->count(LikeService::LIKE_PREVIEW_LIMIT)->create();
        foreach ($blocked as $blockedLiker) {
            $this->like($blockedLiker, $post);
        }

        Sanctum::actingAs($viewer = User::factory()->create());
        foreach ($blocked as $blockedLiker) {
            $this->postJson("/api/v1/users/{$blockedLiker->id}/block")->assertNoContent();
        }

        $this->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.liked_by')
            ->assertJsonPath('data.liked_by.0.id', $visible->id);
        $this->assertSame($viewer->id, $viewer->id);
    }

    public function test_deactivated_likers_are_left_out(): void
    {
        $post = Post::factory()->create();
        $active = User::factory()->create();
        $this->like($active, $post);
        $hidden = User::factory()->create();
        $this->like($hidden, $post);
        // forceFill, not update(): is_active is deliberately absent from User's #[Fillable], the
        // same protection is_admin has, so a mass-assign here is silently dropped.
        $hidden->forceFill(['is_active' => false])->save();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.liked_by')
            ->assertJsonPath('data.liked_by.0.id', $active->id);
    }

    /** The whole point: the feed carries it too, so a page of cards is still one request. */
    public function test_the_feed_carries_the_preview_for_every_post_in_one_request(): void
    {
        $author = User::factory()->create();
        $posts = Post::factory()->for($author)->count(2)->create();
        $liker = User::factory()->create();
        foreach ($posts as $post) {
            $this->like($liker, $post);
        }

        Sanctum::actingAs($viewer = User::factory()->create());
        $viewer->following()->attach($author->id);

        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.liked_by.0.id', $liker->id)
            ->assertJsonPath('data.1.liked_by.0.id', $liker->id);
    }
}
