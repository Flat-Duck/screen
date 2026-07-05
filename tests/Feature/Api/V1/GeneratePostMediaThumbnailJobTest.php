<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QUEUE_CONNECTION=sync in testing (see phpunit.xml), so the thumbnail job dispatched by
 * PostService::createPost() runs inline within these requests — no manual dispatch needed.
 */
class GeneratePostMediaThumbnailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_generates_a_thumbnail_and_marks_media_ready(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 800, 800)],
        ])->assertCreated();

        $media = PostMedia::firstOrFail();

        $this->assertSame(PostMedia::STATUS_READY, $media->status);
        Storage::disk('public')->assertExists($media->thumbnail_path);
    }

    public function test_job_marks_post_ready_once_all_media_are_processed(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 800, 800),
                UploadedFile::fake()->image('two.jpg', 800, 800),
            ],
        ])->assertCreated();

        $post = Post::firstOrFail();

        $this->assertSame(Post::STATUS_READY, $post->status);
        $this->assertTrue($post->media()->where('status', '!=', PostMedia::STATUS_READY)->doesntExist());
    }

    public function test_job_is_a_no_op_when_the_media_row_no_longer_exists(): void
    {
        (new GeneratePostMediaThumbnail(999999))->handle(app(ImageProcessingService::class));

        $this->addToAssertionCount(1); // reaching this line without an exception is the assertion
    }
}
