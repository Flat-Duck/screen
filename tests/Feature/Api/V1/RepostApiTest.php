<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reposting_a_post_succeeds(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson("/api/v1/posts/{$post->id}/repost");

        $response->assertNoContent();
        $this->assertDatabaseCount('reposts', 1);
    }

    public function test_reposting_with_a_quote_comment_succeeds(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson("/api/v1/posts/{$post->id}/repost", ['comment' => 'This is great']);

        $response->assertNoContent();
        $this->assertDatabaseHas('reposts', ['post_id' => $post->id, 'comment' => 'This is great']);
    }

    public function test_a_user_cannot_repost_their_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/posts/{$post->id}/repost");

        $response->assertUnprocessable();
        $this->assertDatabaseCount('reposts', 0);
    }

    public function test_reposting_an_already_reposted_post_is_idempotent(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();
        $response = $this->postJson("/api/v1/posts/{$post->id}/repost");

        $response->assertNoContent();
        $this->assertDatabaseCount('reposts', 1);
    }

    public function test_unreposting_removes_it(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();
        $response = $this->deleteJson("/api/v1/posts/{$post->id}/repost");

        $response->assertNoContent();
        $this->assertDatabaseCount('reposts', 0);
    }

    public function test_reposting_notifies_the_original_author(): void
    {
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $author->id]);
    }

    public function test_reposting_a_blocked_either_way_users_post_is_forbidden(): void
    {
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$author->id}/block")->assertNoContent();

        $response = $this->postJson("/api/v1/posts/{$post->id}/repost");

        $response->assertForbidden();
    }

    public function test_listing_a_users_reposts_returns_the_wrapped_original_post(): void
    {
        $reposter = User::factory()->create();
        $post = Post::factory()->create();
        Sanctum::actingAs($reposter);
        $this->postJson("/api/v1/posts/{$post->id}/repost", ['comment' => 'Look at this'])->assertNoContent();

        $response = $this->getJson("/api/v1/users/{$reposter->id}/reposts");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.comment', 'Look at this');
        $response->assertJsonPath('data.0.post.id', $post->id);
    }

    public function test_reposts_are_not_blended_into_the_repost_authors_own_posts_list(): void
    {
        $reposter = User::factory()->create();
        $post = Post::factory()->create();
        Sanctum::actingAs($reposter);
        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();

        $response = $this->getJson("/api/v1/users/{$reposter->id}/posts");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
