<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\GeneratePostMediaThumbnail;
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

    public function test_deleting_own_post_removes_its_media_files_from_disk(): void
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
        Storage::disk('public')->assertMissing($media->original_path);
        $this->assertDatabaseCount('posts', 0);
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
}
