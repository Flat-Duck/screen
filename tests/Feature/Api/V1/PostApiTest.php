<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_post_stores_ordered_media_and_dispatches_a_thumbnail_job_per_image(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts', [
            'caption' => 'Look at this bug',
            'images' => [
                UploadedFile::fake()->image('one.jpg', 400, 800),
                UploadedFile::fake()->image('two.jpg', 400, 800),
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_media', 2);
        $this->assertSame([0, 1], PostMedia::query()->orderBy('position')->pluck('position')->all());
        Queue::assertPushed(GeneratePostMediaThumbnail::class, 2);
    }

    public function test_creating_a_post_rejects_more_than_ten_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $images = array_map(
            fn () => UploadedFile::fake()->image('shot.jpg', 400, 800),
            range(1, 11),
        );

        $response = $this->postJson('/api/v1/posts', ['images' => $images]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['images']);
    }

    public function test_viewing_a_post_returns_original_url_as_fallback_when_thumbnail_is_not_ready_yet(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $post = Post::factory()->create(['user_id' => $user->id, 'status' => Post::STATUS_PROCESSING]);
        PostMedia::factory()->create([
            'post_id' => $post->id,
            'thumbnail_path' => null,
            'status' => PostMedia::STATUS_PENDING,
        ]);

        $response = $this->getJson("/api/v1/posts/{$post->id}");

        $response->assertOk();
        $response->assertJsonPath('data.media.0.url', $response->json('data.media.0.original_url'));
    }

    public function test_deleting_own_post_soft_deletes_it_and_keeps_media_files_on_disk(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)],
        ]);
        $postId = $create->json('data.id');

        $media = PostMedia::firstOrFail();
        Storage::disk('public')->assertExists($media->original_path);

        $response = $this->deleteJson("/api/v1/posts/{$postId}");

        $response->assertNoContent();
        Storage::disk('public')->assertExists($media->original_path);
        $this->assertSoftDeleted('posts', ['id' => $postId]);
    }

    public function test_a_soft_deleted_post_404s_and_disappears_from_listings(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertNoContent();

        $this->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$owner->id}/posts")->assertJsonCount(0, 'data');
    }

    public function test_deleting_a_comment_still_works_after_its_post_is_soft_deleted(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertNoContent();

        $response = $this->deleteJson("/api/v1/comments/{$comment->id}");

        $response->assertNoContent();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_deleting_another_users_post_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $owner->id]);

        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $response = $this->deleteJson("/api/v1/posts/{$post->id}");

        $response->assertForbidden();
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_viewing_a_post_reflects_is_liked_for_the_current_viewer_only(): void
    {
        $post = Post::factory()->create();
        $liker = User::factory()->create();
        $otherViewer = User::factory()->create();

        Sanctum::actingAs($liker);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();

        $asLiker = $this->getJson("/api/v1/posts/{$post->id}");
        $asLiker->assertOk();
        $asLiker->assertJsonPath('data.is_liked', true);

        Sanctum::actingAs($otherViewer);
        $asOther = $this->getJson("/api/v1/posts/{$post->id}");
        $asOther->assertOk();
        $asOther->assertJsonPath('data.is_liked', false);
    }

    public function test_post_resource_returns_the_full_expected_shape(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/posts', [
            'caption' => 'Look at this bug',
            'images' => [UploadedFile::fake()->image('one.jpg', 400, 800)],
        ]);
        $postId = $create->json('data.id');

        $response = $this->getJson("/api/v1/posts/{$postId}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'caption', 'status',
                'user' => ['id', 'username', 'name', 'avatar_url'],
                'media' => [['id', 'position', 'url', 'original_url', 'width', 'height', 'status']],
                'likes_count', 'comments_count', 'is_liked', 'created_at',
            ],
        ]);
    }

    public function test_creating_a_post_rejects_an_image_over_the_size_limit(): void
    {
        Storage::fake('public');

        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)->size(10241)],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['images.0']);
    }

    public function test_creating_a_post_accepts_an_image_at_the_size_limit(): void
    {
        Storage::fake('public');
        Queue::fake();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)->size(10240)],
        ]);

        $response->assertCreated();
    }

    public function test_creating_a_post_rejects_a_non_image_file_disguised_with_an_image_extension(): void
    {
        Storage::fake('public');

        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->create('shot.jpg', 10, 'application/octet-stream')],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['images.0']);
    }

    public function test_creating_a_post_rejects_an_image_smaller_than_the_minimum_dimensions(): void
    {
        Storage::fake('public');

        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 199, 199)],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['images.0']);
    }
}
