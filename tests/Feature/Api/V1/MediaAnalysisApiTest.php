<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use App\Models\Group;
use App\Models\MediaAnalysis;
use App\Models\Post;
use App\Models\ScreenshotCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MediaAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_returns_categories_and_regions_without_detected_values(): void
    {
        Storage::fake('public');
        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()->andReturn(
            new TextExtractionResult('password = do-not-return-this', 'eng'),
        );
        $this->app->instance(ScreenshotTextExtractor::class, $extractor);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/media/analyses', [
            'images' => [UploadedFile::fake()->image('screen.png', 400, 800)],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.status', MediaAnalysis::STATUS_READY)
            ->assertJsonPath('data.requires_acknowledgement', true)
            ->assertJsonPath('data.items.0.safety_status', 'warning')
            ->assertJsonPath('data.items.0.findings.0.category', 'credential')
            ->assertJsonPath('data.items.0.findings.0.region.width', 1)
            // A "warning" item never gets a suggested alt text — see
            // MediaAnalysisItem::suggestedAltText's kdoc: it would just re-expose the same
            // sensitive text the warning exists to catch.
            ->assertJsonPath('data.items.0.suggested_alt_text', null);
        $this->assertStringNotContainsString('do-not-return-this', $response->getContent());
        $this->assertStringNotContainsString(
            'do-not-return-this',
            (string) DB::table('media_analysis_items')->value('ocr_text'),
        );
    }

    public function test_clear_analysis_suggests_alt_text_from_ocr_and_client_can_override_it(): void
    {
        Storage::fake('public');
        $this->bindOcrText('Settings > Notifications > Push alerts');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $this->getJson("/api/v1/media/analyses/{$analysis->token}")
            ->assertJsonPath('data.items.0.suggested_alt_text', 'Settings > Notifications > Push alerts');

        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'alt_text' => 'A custom description the poster typed instead',
        ])->assertCreated();

        $post = Post::firstOrFail();
        $this->assertDatabaseHas('post_media', [
            'post_id' => $post->id,
            'alt_text' => 'A custom description the poster typed instead',
        ]);
    }

    public function test_publishing_without_an_alt_text_override_falls_back_to_the_ocr_suggestion(): void
    {
        Storage::fake('public');
        $this->bindOcrText('Battery: 87 percent remaining');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $post = Post::firstOrFail();
        $this->assertDatabaseHas('post_media', ['post_id' => $post->id, 'alt_text' => 'Battery: 87 percent remaining']);
    }

    public function test_category_is_derived_from_hashtags_and_ocr_text_not_client_input(): void
    {
        Storage::fake('public');
        $this->bindOcrText('def main(): print("hello world") # python function example');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();
        $code = ScreenshotCategory::query()->where('slug', 'code')->firstOrFail();

        // category_id is not an accepted field anymore — sending one is silently ignored, the
        // server's own hashtag/OCR match wins regardless of what the client asks for.
        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'Cleaning up my #code before the review',
            'category_id' => 999999,
        ])->assertCreated();

        $response->assertJsonPath('data.category.slug', 'code');
        $this->assertSame($code->id, Post::firstOrFail()->category_id);
    }

    public function test_caption_with_no_matching_keywords_leaves_the_post_uncategorized(): void
    {
        Storage::fake('public');
        $this->bindOcrText('');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'just a normal day',
        ])->assertCreated();

        $response->assertJsonPath('data.category', null);
        $this->assertNull(Post::firstOrFail()->category_id);
    }

    public function test_warning_must_be_acknowledged_before_a_post_is_created(): void
    {
        Storage::fake('public');
        $this->bindOcrText('api_key = highly-sensitive');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $analysis = $this->createAnalysis();

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", ['caption' => 'Safe now'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acknowledge_sensitive');
        $this->assertDatabaseCount('posts', 0);

        // content_warning isn't accepted from the client either — it's set to "sensitive"
        // automatically whenever any item's safety_status came back "warning".
        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'Continue intentionally',
            'acknowledge_sensitive' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.content_warning', 'sensitive');
        $post = Post::firstOrFail();
        $this->assertNotNull($post->safety_acknowledged_at);
        $this->assertSame('fake-v1+sensitive-patterns-v1', $post->safety_analysis_version);
        $this->assertDatabaseMissing('media_analyses', ['id' => $analysis->id]);
        // No suggested alt text either, same reasoning as the "warning" item test above.
        $this->assertDatabaseHas('post_media', ['post_id' => $post->id, 'alt_text' => null]);
    }

    public function test_clear_analysis_can_publish_without_acknowledgement(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary application settings');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")
            ->assertCreated();

        $this->assertNull(Post::firstOrFail()->safety_acknowledged_at);
    }

    public function test_publishing_with_a_group_id_posts_directly_into_that_group(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary screen');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $group = Group::query()->create(['creator_id' => $user->id, 'name' => 'Photography', 'visibility' => 'public']);
        $group->members()->create(['user_id' => $user->id, 'role' => 'admin']);
        $analysis = $this->createAnalysis();

        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'For the group',
            'group_id' => $group->id,
        ])->assertCreated();

        $post = Post::firstOrFail();
        $this->assertDatabaseHas('group_posts', ['group_id' => $group->id, 'post_id' => $post->id]);
        $this->assertSame($post->id, $response->json('data.id'));
        // Still a normal timeline post underneath — posting into a group is additive, not a
        // group-exclusive post type.
        $this->assertSame($user->id, $post->user_id);
    }

    public function test_publishing_with_a_group_id_the_user_does_not_belong_to_is_rejected_and_creates_no_post(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary screen');
        Sanctum::actingAs(User::factory()->create());
        $group = Group::query()->create(['creator_id' => User::factory()->create()->id, 'name' => 'Not My Group', 'visibility' => 'public']);
        $analysis = $this->createAnalysis();

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'Sneaking in',
            'group_id' => $group->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_analysis_token_is_owner_scoped_for_read_publish_and_cancel(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary screen');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/media/analyses/{$analysis->token}")->assertNotFound();
        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertNotFound();
        $this->deleteJson("/api/v1/media/analyses/{$analysis->token}")->assertNotFound();
        $this->assertDatabaseHas('media_analyses', ['id' => $analysis->id]);
    }

    public function test_expired_analysis_cannot_be_read_or_published_and_cleanup_removes_it(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary screen');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();
        $analysis->update(['expires_at' => now()->subMinute()]);
        DB::table('media_cleanup_tasks')->where('id', $analysis->cleanup_task_id)->update(['available_at' => now()->subMinute()]);

        $this->getJson("/api/v1/media/analyses/{$analysis->token}")->assertGone();
        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertGone();

        $this->artisan('media:clean-orphans')->assertSuccessful();
        $this->assertDatabaseMissing('media_analyses', ['id' => $analysis->id]);
        Storage::disk('public')->assertMissing($analysis->directory);
    }

    public function test_owner_can_cancel_and_remove_staged_files(): void
    {
        Storage::fake('public');
        $this->bindOcrText('ordinary screen');
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $this->deleteJson("/api/v1/media/analyses/{$analysis->token}")->assertNoContent();

        $this->assertDatabaseMissing('media_analyses', ['id' => $analysis->id]);
        Storage::disk('public')->assertMissing($analysis->directory);
    }

    public function test_processing_analysis_cannot_be_published(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $analysis = $this->createAnalysis();

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")
            ->assertConflict();
        $this->assertDatabaseCount('posts', 0);
    }

    private function bindOcrText(string $text): void
    {
        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()->andReturn(new TextExtractionResult($text, 'eng'));
        $this->app->instance(ScreenshotTextExtractor::class, $extractor);
    }

    private function createAnalysis(): MediaAnalysis
    {
        $response = $this->postJson('/api/v1/media/analyses', [
            'images' => [UploadedFile::fake()->image('screen.png', 400, 800)],
        ])->assertAccepted();

        return MediaAnalysis::query()->where('token', $response->json('data.token'))->firstOrFail();
    }
}
