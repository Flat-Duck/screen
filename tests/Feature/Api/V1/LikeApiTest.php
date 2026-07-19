<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LikeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_liking_a_post_increments_its_like_count(): void
    {
        $post = Post::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/posts/{$post->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 1]);
        $this->assertDatabaseCount('likes', 1);
    }

    public function test_liking_an_already_liked_post_is_idempotent(): void
    {
        $post = Post::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
        $response = $this->postJson("/api/v1/posts/{$post->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 1]);
        $this->assertDatabaseCount('likes', 1);
    }

    public function test_unliking_removes_the_like(): void
    {
        $post = Post::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
        $response = $this->deleteJson("/api/v1/posts/{$post->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 0]);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_liking_a_comment_increments_its_like_count(): void
    {
        $comment = Comment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson("/api/v1/comments/{$comment->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 1]);
        $this->assertDatabaseHas('likes', ['likeable_type' => Comment::class, 'likeable_id' => $comment->id]);
    }

    public function test_liking_an_already_liked_comment_is_idempotent(): void
    {
        $comment = Comment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/comments/{$comment->id}/like")->assertOk();
        $response = $this->postJson("/api/v1/comments/{$comment->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 1]);
    }

    public function test_unliking_a_comment_removes_the_like(): void
    {
        $comment = Comment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/comments/{$comment->id}/like")->assertOk();
        $response = $this->deleteJson("/api/v1/comments/{$comment->id}/like");

        $response->assertOk();
        $response->assertJson(['likes_count' => 0]);
    }

    public function test_liking_a_post_and_a_comment_are_independent(): void
    {
        $post = Post::factory()->create();
        $comment = Comment::factory()->create(['post_id' => $post->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
        $this->postJson("/api/v1/comments/{$comment->id}/like")->assertOk();

        $this->assertDatabaseCount('likes', 2);
        $this->getJson("/api/v1/posts/{$post->id}")->assertJsonPath('data.likes_count', 1);
    }

    public function test_liking_a_comment_notifies_its_author(): void
    {
        $author = User::factory()->create();
        $comment = Comment::factory()->create(['user_id' => $author->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/comments/{$comment->id}/like")->assertOk();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $author->id]);
    }
}
