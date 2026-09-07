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
 * CreatePost dispatches the job after commit; QUEUE_CONNECTION=sync in testing means it
 * still runs during these requests without manual dispatch.
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

    /**
     * The placeholder rides along with the thumbnail deliberately: this job is the only place in
     * the pipeline that already has the image decoded, so computing it anywhere else would mean a
     * second fetch out of the object store and a second decode, per image.
     */
    public function test_job_records_a_placeholder_and_the_original_dimensions(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 900, 400)],
        ])->assertCreated();

        $media = PostMedia::firstOrFail();

        $this->assertNotNull($media->thumbhash);
        // Base64 of a ~25-byte hash. Asserting it decodes and is plausibly sized catches a column
        // that is being written garbage; ThumbHashTest is what proves the bytes are correct.
        $decoded = base64_decode($media->thumbhash, true);
        $this->assertIsString($decoded);
        $this->assertGreaterThanOrEqual(5, strlen($decoded));
        $this->assertLessThanOrEqual(48, strlen($decoded));

        // The original's dimensions, not the 640px thumbnail's — the client needs the real ratio.
        $this->assertSame(900, $media->width);
        $this->assertSame(400, $media->height);
    }

    /** The API has to hand the placeholder to the client, or none of the above is worth anything. */
    public function test_the_placeholder_is_exposed_on_the_post_resource(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $post = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 400)],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/posts/{$post}")
            ->assertOk()
            ->assertJsonPath('data.media.0.thumbhash', PostMedia::firstOrFail()->thumbhash);
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
