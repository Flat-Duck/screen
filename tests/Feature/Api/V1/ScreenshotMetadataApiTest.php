<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\ScreenshotCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScreenshotMetadataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_metadata_remains_aligned_with_carousel_positions(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [
                UploadedFile::fake()->image('first.jpg', 400, 800),
                UploadedFile::fake()->image('second.jpg', 400, 800),
            ],
            'media_metadata' => [
                ['alt_text' => 'First screenshot'],
                ['alt_text' => 'Second screenshot'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.media.0.alt_text', 'First screenshot')
            ->assertJsonPath('data.media.1.alt_text', 'Second screenshot');
        $this->assertSame(
            ['First screenshot', 'Second screenshot'],
            PostMedia::query()->orderBy('position')->pluck('alt_text')->all(),
        );
    }

    public function test_media_metadata_must_have_one_entry_per_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/posts', [
            'images' => [
                UploadedFile::fake()->image('first.jpg', 400, 800),
                UploadedFile::fake()->image('second.jpg', 400, 800),
            ],
            'media_metadata' => [['alt_text' => 'Only one']],
        ])->assertUnprocessable()->assertJsonValidationErrors('media_metadata');
    }

    public function test_owner_can_update_alt_text_without_replacing_media(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/original.jpg']);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/posts/{$post->id}/media/{$media->id}", ['alt_text' => 'Accessible description'])
            ->assertOk()
            ->assertJsonPath('data.alt_text', 'Accessible description');

        $this->assertDatabaseHas('post_media', [
            'id' => $media->id,
            'original_path' => 'posts/original.jpg',
            'alt_text' => 'Accessible description',
        ]);
    }

    public function test_non_owner_cannot_update_alt_text_and_length_is_limited(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        $media = PostMedia::factory()->for($post)->create();

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/posts/{$post->id}/media/{$media->id}", ['alt_text' => 'No'])
            ->assertForbidden();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/posts/{$post->id}/media/{$media->id}", ['alt_text' => str_repeat('a', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('alt_text');
    }

    public function test_media_must_belong_to_the_post_in_the_route(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        $otherMedia = PostMedia::factory()->create();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/posts/{$post->id}/media/{$otherMedia->id}", ['alt_text' => 'No'])
            ->assertNotFound();
    }

    public function test_structured_context_is_returned_and_unsafe_urls_are_rejected(): void
    {
        Storage::fake('public');
        Queue::fake();
        $category = ScreenshotCategory::query()->where('slug', 'code')->firstOrFail();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/posts', [
            'images' => [UploadedFile::fake()->image('code.jpg', 400, 800)],
            'category_id' => $category->id,
            'source_application' => 'GitHub',
            'source_url' => 'https://github.com/example/project',
            'content_warning' => 'spoiler',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.category.slug', 'code')
            ->assertJsonPath('data.source_application', 'GitHub')
            ->assertJsonPath('data.source_url', 'https://github.com/example/project')
            ->assertJsonPath('data.content_warning', 'spoiler');

        foreach (['javascript:alert(1)', 'http://localhost/secret', 'http://127.0.0.1/admin', 'http://[::1]/'] as $url) {
            $this->postJson('/api/v1/posts', [
                'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)],
                'source_url' => $url,
            ])->assertUnprocessable()->assertJsonValidationErrors('source_url');
        }
    }

    public function test_resource_suppresses_legacy_unsafe_urls_and_private_ocr_fields(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->for($viewer)->create(['source_url' => 'http://127.0.0.1/internal']);
        PostMedia::factory()->for($post)->create([
            'ocr_text' => 'private extracted text',
            'perceptual_hash' => 'internal-hash',
        ]);
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->assertJsonPath('data.source_url', null);
        $response->assertJsonMissingPath('data.media.0.ocr_text');
        $response->assertJsonMissingPath('data.media.0.perceptual_hash');
    }

    public function test_active_screenshot_categories_are_discoverable_in_order(): void
    {
        ScreenshotCategory::query()->where('slug', 'social')->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/screenshot-categories')->assertOk();
        $response->assertJsonMissing(['slug' => 'social']);
        $this->assertSame('messaging', $response->json('data.0.slug'));
    }
}
