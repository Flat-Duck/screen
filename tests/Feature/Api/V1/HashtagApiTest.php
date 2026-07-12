<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HashtagApiTest extends TestCase
{
    use RefreshDatabase;

    private function createPost(User $user, ?string $caption): int
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts', [
            'caption' => $caption,
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)],
        ]);

        return $response->json('data.id');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_creating_a_post_extracts_hashtags_from_the_caption(): void
    {
        $postId = $this->createPost(User::factory()->create(), 'Found a #screenshot #bug today');

        $post = Post::query()->with('hashtags')->findOrFail($postId);

        $this->assertSame(['bug', 'screenshot'], $post->hashtags->pluck('name')->sort()->values()->all());
    }

    public function test_hashtag_extraction_is_case_insensitive_and_deduped(): void
    {
        $postId = $this->createPost(User::factory()->create(), '#Bug #bug #BUG');

        $post = Post::query()->with('hashtags')->findOrFail($postId);

        $this->assertDatabaseCount('hashtags', 1);
        $this->assertSame(['bug'], $post->hashtags->pluck('name')->all());
    }

    public function test_hashtag_extraction_supports_unicode_scripts(): void
    {
        $postId = $this->createPost(User::factory()->create(), 'شاهد #لقطة_شاشة الجديدة');

        $post = Post::query()->with('hashtags')->findOrFail($postId);

        $this->assertSame(['لقطة_شاشة'], $post->hashtags->pluck('name')->all());
    }

    public function test_creating_a_post_without_hashtags_creates_no_hashtag_rows(): void
    {
        $this->createPost(User::factory()->create(), 'Just a plain caption');

        $this->assertDatabaseCount('hashtags', 0);
        $this->assertDatabaseCount('hashtag_post', 0);
    }

    public function test_top_tags_returns_the_users_most_used_hashtags_first(): void
    {
        $user = User::factory()->create();
        $this->createPost($user, '#bug #android');
        $this->createPost($user, '#bug #crash');
        $this->createPost($user, '#bug');

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson("/api/v1/users/{$user->id}/top-tags");

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'bug');
        $response->assertJsonPath('data.0.posts_count', 3);
    }

    public function test_top_tags_does_not_count_soft_deleted_posts(): void
    {
        $user = User::factory()->create();
        $postId = $this->createPost($user, '#bug');

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/posts/{$postId}")->assertNoContent();

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson("/api/v1/users/{$user->id}/top-tags");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_top_tags_only_counts_the_requested_users_own_posts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createPost($user, '#bug');
        $this->createPost($otherUser, '#bug #bug'); // dedup within one caption anyway

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson("/api/v1/users/{$user->id}/top-tags");

        $response->assertOk();
        $response->assertJsonPath('data.0.posts_count', 1);
    }
}
