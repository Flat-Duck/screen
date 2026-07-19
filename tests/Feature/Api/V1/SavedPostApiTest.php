<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedPostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_post_succeeds(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson("/api/v1/posts/{$post->id}/save");

        $response->assertNoContent();
        $this->assertDatabaseCount('saved_posts', 1);
    }

    public function test_saving_an_already_saved_post_is_idempotent(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/save")->assertNoContent();
        $response = $this->postJson("/api/v1/posts/{$post->id}/save");

        $response->assertNoContent();
        $this->assertDatabaseCount('saved_posts', 1);
    }

    public function test_unsaving_removes_it(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/posts/{$post->id}/save")->assertNoContent();
        $response = $this->deleteJson("/api/v1/posts/{$post->id}/save");

        $response->assertNoContent();
        $this->assertDatabaseCount('saved_posts', 0);
    }

    public function test_saved_posts_are_private_to_the_saver(): void
    {
        $post = Post::factory()->create();
        $saver = User::factory()->create();
        Sanctum::actingAs($saver);
        $this->postJson("/api/v1/posts/{$post->id}/save")->assertNoContent();

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson('/api/v1/saved-posts');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_listing_saved_posts_returns_only_my_saved_posts(): void
    {
        $saved = Post::factory()->create();
        Post::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/posts/{$saved->id}/save")->assertNoContent();

        $response = $this->getJson('/api/v1/saved-posts');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $saved->id);
        $response->assertJsonPath('data.0.is_saved', true);
    }

    public function test_viewing_a_post_reflects_is_saved_for_the_current_viewer_only(): void
    {
        $post = Post::factory()->create();
        $saver = User::factory()->create();
        $otherViewer = User::factory()->create();

        Sanctum::actingAs($saver);
        $this->postJson("/api/v1/posts/{$post->id}/save")->assertNoContent();

        $asSaver = $this->getJson("/api/v1/posts/{$post->id}");
        $asSaver->assertJsonPath('data.is_saved', true);

        Sanctum::actingAs($otherViewer);
        $asOther = $this->getJson("/api/v1/posts/{$post->id}");
        $asOther->assertJsonPath('data.is_saved', false);
    }
}
