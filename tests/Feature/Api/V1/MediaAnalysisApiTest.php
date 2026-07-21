<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use App\Models\MediaAnalysis;
use App\Models\Post;
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
            'media_metadata' => [['alt_text' => 'Settings screen']],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.status', MediaAnalysis::STATUS_READY)
            ->assertJsonPath('data.requires_acknowledgement', true)
            ->assertJsonPath('data.items.0.safety_status', 'warning')
            ->assertJsonPath('data.items.0.findings.0.category', 'credential')
            ->assertJsonPath('data.items.0.findings.0.region.width', 1);
        $this->assertStringNotContainsString('do-not-return-this', $response->getContent());
        $this->assertStringNotContainsString(
            'do-not-return-this',
            (string) DB::table('media_analysis_items')->value('ocr_text'),
        );
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

        $response = $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish", [
            'caption' => 'Continue intentionally',
            'acknowledge_sensitive' => true,
            'content_warning' => 'sensitive',
        ]);

        $response->assertCreated()->assertJsonPath('data.content_warning', 'sensitive');
        $post = Post::firstOrFail();
        $this->assertNotNull($post->safety_acknowledged_at);
        $this->assertSame('fake-v1+sensitive-patterns-v1', $post->safety_analysis_version);
        $this->assertDatabaseMissing('media_analyses', ['id' => $analysis->id]);
        $this->assertDatabaseHas('post_media', ['post_id' => $post->id, 'alt_text' => 'A screenshot']);
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
            'media_metadata' => [['alt_text' => 'A screenshot']],
        ])->assertAccepted();

        return MediaAnalysis::query()->where('token', $response->json('data.token'))->firstOrFail();
    }
}
