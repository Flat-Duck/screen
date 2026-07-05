<?php

namespace Tests\Feature\Api\V1;

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
}
