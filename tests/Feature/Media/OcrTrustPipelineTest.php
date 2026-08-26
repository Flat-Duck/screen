<?php

namespace Tests\Feature\Media;

use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\SafetyAnalysisResult;
use App\Data\Screenshots\TextExtractionResult;
use App\Jobs\AnalyzeStagedScreenshot;
use App\Models\MediaAnalysis;
use App\Models\MediaAnalysisItem;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserOcrTrust;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Covers the direct-to-R2 `upload_ids` path added to `POST media/analyses` — see
 * docs/SECURITY.md §12. The legacy raw-multipart path is covered by MediaAnalysisApiTest and is
 * untouched by any of this.
 */
class OcrTrustPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_trusted_user_resolves_the_upload_synchronously_without_dispatching_ocr(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED, 'consecutive_verified_count' => 25]);
        $upload = $this->uploadedFor($user, 'ordinary screen with no sensitive content at all');
        Sanctum::actingAs($user);

        // A trusted account is still spot-checked at OcrTrustSampler::TRUSTED_SAMPLE_RATE_PERCENT
        // (8%), so asserting the not-sampled outcome against the real sampler fails roughly one
        // run in twelve. This test is about what happens *when* the sampler says "don't sample",
        // so pin that decision; the sample rate itself is covered by OcrTrustSamplerTest.
        $sampler = Mockery::mock(OcrTrustSampler::class);
        $sampler->shouldReceive('shouldSample')->andReturn(false);
        $this->instance(OcrTrustSampler::class, $sampler);

        $response = $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]]);

        $response->assertAccepted()->assertJsonPath('data.status', MediaAnalysis::STATUS_READY);
        Queue::assertNotPushed(AnalyzeStagedScreenshot::class);
        $this->assertDatabaseHas('media_analysis_items', [
            'upload_id' => $upload->id,
            'ocr_source' => 'device',
            'verification_status' => 'not_sampled',
            'ocr_status' => 'ready',
        ]);
        $this->assertSame(Upload::STATUS_VERIFIED, $upload->fresh()->status);
    }

    public function test_a_new_user_always_samples_and_dispatches_ocr(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $upload = $this->uploadedFor($user, 'ordinary screen');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]]);

        $response->assertAccepted()->assertJsonPath('data.status', MediaAnalysis::STATUS_PROCESSING);
        Queue::assertPushed(AnalyzeStagedScreenshot::class, 1);
        $this->assertDatabaseHas('media_analysis_items', [
            'upload_id' => $upload->id,
            'ocr_source' => 'device',
            'verification_status' => 'pending',
        ]);
        $this->assertSame(Upload::STATUS_VERIFIED, $upload->fresh()->status);
    }

    public function test_referencing_someone_elses_upload_id_is_rejected_and_leaves_no_orphaned_analysis(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $upload = $this->uploadedFor($owner, 'ordinary screen');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]])
            ->assertNotFound();

        $this->assertDatabaseCount('media_analyses', 0);
        $this->assertDatabaseCount('media_analysis_items', 0);
    }

    public function test_sending_both_images_and_upload_ids_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/media/analyses', ['upload_ids' => ['not-even-checked']])
            ->assertUnprocessable();
    }

    public function test_publish_resolves_a_sampled_mismatch_and_demotes_the_account_to_probation(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED, 'consecutive_verified_count' => 10]);
        $analysis = $this->readyAnalysisWithPendingItem($user, deviceText: 'python function exception github programming', serverText: 'a completely unrelated vacation photo');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        // The item itself (and its resolved verification_status) is cascade-deleted along with
        // the whole MediaAnalysis at the end of publish, same as the legacy path — the trust-tier
        // update below is the persisted, meaningful outcome of the comparison.
        $trust = UserOcrTrust::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(UserOcrTrust::TIER_PROBATION, $trust->trust_tier);
        $this->assertSame(0, $trust->consecutive_verified_count);
        $this->assertNotNull($trust->last_mismatch_at);
    }

    public function test_publish_resolves_a_sampled_match_and_credits_the_account(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_NEW, 'consecutive_verified_count' => 5]);
        $analysis = $this->readyAnalysisWithPendingItem($user, deviceText: 'ordinary screen', serverText: 'ordinary screen');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $this->assertSame(6, UserOcrTrust::where('user_id', $user->id)->value('consecutive_verified_count'));
    }

    public function test_publish_marks_the_upload_published_and_carries_the_source_disk_onto_post_media(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        $analysis = $this->readyAnalysisWithPendingItem($user, deviceText: 'ordinary screen', serverText: 'ordinary screen');
        $item = $analysis->items->first();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $this->assertSame(Upload::STATUS_PUBLISHED, Upload::find($item->upload_id)->status);
        $this->assertDatabaseHas('post_media', ['original_path' => $item->original_path, 'source_disk' => 'r2']);
    }

    public function test_analyze_staged_screenshot_reads_a_sampled_r2_item_from_its_own_disk(): void
    {
        $user = User::factory()->create();
        $upload = $this->uploadedFor($user, 'device claim');
        $analysis = MediaAnalysis::create([
            'token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'directory' => 'analyses/'.Str::uuid(),
            'status' => MediaAnalysis::STATUS_PROCESSING,
            'expires_at' => now()->addMinutes(10),
        ]);
        $item = MediaAnalysisItem::create([
            'media_analysis_id' => $analysis->id,
            'upload_id' => $upload->id,
            'position' => 0,
            'original_path' => $upload->object_key,
            'source_disk' => 'r2',
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'ocr_status' => 'pending',
            'safety_status' => 'pending',
            'ocr_source' => 'device',
            'verification_status' => 'pending',
            'device_ocr_text' => 'device claim',
        ]);

        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()
            ->with('r2', $upload->object_key)
            ->andReturn(new TextExtractionResult('server result', 'eng'));
        $analyzer = Mockery::mock(ScreenshotSafetyAnalyzer::class);
        $analyzer->allows('version')->andReturn('safety-v1');
        $analyzer->allows('analyze')->andReturn(new SafetyAnalysisResult([]));

        (new AnalyzeStagedScreenshot($item->id))->handle($extractor, $analyzer);

        $this->assertSame('server result', $item->fresh()->ocr_text);
        $this->assertSame(MediaAnalysis::STATUS_READY, $analysis->fresh()->status);
    }

    private function uploadedFor(User $user, string $ocrText): Upload
    {
        return Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/'.$user->id.'/'.Str::uuid().'.jpg',
            'nonce' => Str::random(43),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
            'ocr_text' => $ocrText,
            'status' => Upload::STATUS_UPLOADED,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /** Builds a MediaAnalysis already in the post-AnalyzeStagedScreenshot state a sampled upload
     * would be in — device_ocr_text is the original claim, ocr_text is what the (mocked-out)
     * server-side job would have produced. Ready to publish without a caption, so category
     * differences are driven purely by these OCR texts. */
    private function readyAnalysisWithPendingItem(User $user, string $deviceText, string $serverText): MediaAnalysis
    {
        $upload = $this->uploadedFor($user, $deviceText);
        $analysis = MediaAnalysis::create([
            'token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'directory' => 'analyses/'.Str::uuid(),
            'status' => MediaAnalysis::STATUS_READY,
            'expires_at' => now()->addMinutes(10),
        ]);
        MediaAnalysisItem::create([
            'media_analysis_id' => $analysis->id,
            'upload_id' => $upload->id,
            'position' => 0,
            'original_path' => $upload->object_key,
            'source_disk' => 'r2',
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'ocr_status' => 'ready',
            'safety_status' => 'clear',
            'ocr_source' => 'device',
            'verification_status' => 'pending',
            'device_ocr_text' => $deviceText,
            'ocr_text' => $serverText,
        ]);

        return $analysis->fresh('items');
    }
}
