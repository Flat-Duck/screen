<?php

namespace Tests\Feature\Ocr;

use App\Models\MediaAnalysis;
use App\Models\MediaAnalysisItem;
use App\Models\OcrVerification;
use App\Models\PostMedia;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserOcrTrust;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The comparison the trust loop performs at publish used to die with the MediaAnalysis a few
 * lines later, so "is device OCR getting better?" was unanswerable. These lock the durable
 * record that replaces it.
 */
class OcrEvaluationRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sampled_match_is_recorded_with_a_real_similarity_score(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        $analysis = $this->readyAnalysis($user, deviceText: 'python function exception', serverText: 'python function exception');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $verification = OcrVerification::query()->sole();
        $this->assertSame(OcrVerification::VERDICT_MATCH, $verification->verdict);
        $this->assertSame('device', $verification->ocr_source);
        $this->assertSame(1.0, $verification->similarity);
        $this->assertTrue($verification->category_matched);
        $this->assertSame($user->id, $verification->user_id);
    }

    public function test_a_mismatch_records_the_low_similarity_and_the_tier_it_caused(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED, 'consecutive_verified_count' => 10]);
        $analysis = $this->readyAnalysis($user, deviceText: 'python function exception github programming', serverText: 'a completely unrelated vacation photo');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $verification = OcrVerification::query()->sole();
        $this->assertSame(OcrVerification::VERDICT_MISMATCH, $verification->verdict);
        $this->assertSame(0.0, $verification->similarity);
        // The tier movement, which user_ocr_trust cannot answer later because it only ever
        // holds current state.
        $this->assertSame(UserOcrTrust::TIER_TRUSTED, $verification->trust_tier_before);
        $this->assertSame(UserOcrTrust::TIER_PROBATION, $verification->trust_tier_after);
    }

    public function test_the_record_stores_hashes_and_counts_never_the_text(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        $analysis = $this->readyAnalysis($user, deviceText: 'secret token abc123', serverText: 'secret token abc123');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        $row = (array) DB::table('ocr_verifications')->sole();

        // This table is permanent; the text it describes is user content full of credentials.
        foreach ($row as $value) {
            $this->assertStringNotContainsString('secret token abc123', (string) $value);
        }
        $this->assertSame(hash('sha256', 'secret token abc123'), $row['device_text_hash']);
        $this->assertSame(19, (int) $row['device_char_count']);
    }

    public function test_an_unsampled_trusted_claim_is_recorded_as_unverified(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        UserOcrTrust::create(['user_id' => $user->id, 'trust_tier' => UserOcrTrust::TIER_TRUSTED, 'consecutive_verified_count' => 25]);
        $sampler = Mockery::mock(OcrTrustSampler::class);
        $sampler->shouldReceive('shouldSample')->andReturn(false);
        $sampler->shouldReceive('tierFor')->andReturn(UserOcrTrust::TIER_TRUSTED);
        $this->instance(OcrTrustSampler::class, $sampler);

        $upload = $this->uploadFor($user, 'trusted device text');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]])->assertAccepted();
        $token = MediaAnalysis::query()->sole()->token;

        $this->postJson("/api/v1/media/analyses/{$token}/publish")->assertCreated();

        $verification = OcrVerification::query()->sole();
        // How much device OCR is accepted with no check at all is the number that says
        // whether the 8% sample rate is set sensibly.
        $this->assertSame(OcrVerification::VERDICT_UNVERIFIED, $verification->verdict);
        $this->assertNull($verification->similarity);
        $this->assertNull($verification->server_text_hash);
        $this->assertSame(0, OcrVerification::query()->compared()->count());
    }

    public function test_server_sourced_media_records_nothing(): void
    {
        // There is no device claim to compare against, so a row would say nothing and would
        // dilute every agreement rate computed over the table.
        PostMedia::factory()->create(['ocr_source' => PostMedia::OCR_SOURCE_SERVER]);

        $this->assertSame(0, OcrVerification::query()->count());
    }

    public function test_publish_carries_the_ocr_source_and_duration_onto_the_published_media(): void
    {
        Storage::fake('r2');
        Queue::fake();
        $user = User::factory()->create();
        $analysis = $this->readyAnalysis($user, deviceText: 'ordinary screen', serverText: 'ordinary screen');
        $analysis->items->first()->update(['ocr_duration_ms' => 1234]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/media/analyses/{$analysis->token}/publish")->assertCreated();

        // Provenance previously existed only on the analysis item, which is deleted at publish.
        $this->assertDatabaseHas('post_media', ['ocr_source' => 'device', 'ocr_duration_ms' => 1234]);
    }

    private function uploadFor(User $user, string $ocrText): Upload
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

    private function readyAnalysis(User $user, string $deviceText, string $serverText): MediaAnalysis
    {
        $upload = $this->uploadFor($user, $deviceText);
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
