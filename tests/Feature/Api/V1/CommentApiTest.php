<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_comment_returns_it_with_the_authors_summary(): void
    {
        $post = Post::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Nice catch!']);

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Nice catch!');
        $response->assertJsonPath('data.user.id', $user->id);
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_deleting_own_comment_succeeds(): void
    {
        $user = User::factory()->create();
        $comment = Comment::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/comments/{$comment->id}");

        $response->assertNoContent();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_deleting_someone_elses_comment_on_someone_elses_post_is_forbidden(): void
    {
        $postOwner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $postOwner->id]);
        $commenter = User::factory()->create();
        $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $commenter->id]);

        $bystander = User::factory()->create();
        Sanctum::actingAs($bystander);

        $response = $this->deleteJson("/api/v1/comments/{$comment->id}");

        $response->assertForbidden();
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_a_post_owner_can_delete_a_comment_on_their_own_post(): void
    {
        $postOwner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $postOwner->id]);
        $commenter = User::factory()->create();
        $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $commenter->id]);

        Sanctum::actingAs($postOwner);

        $response = $this->deleteJson("/api/v1/comments/{$comment->id}");

        $response->assertNoContent();
        $this->assertDatabaseCount('comments', 0);
    }
}
