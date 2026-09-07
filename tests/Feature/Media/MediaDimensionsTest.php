<?php

namespace Tests\Feature\Media;

use App\Models\Post;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserOcrTrust;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * `width`/`height` have to survive the whole direct-to-R2 path, because the client uses them to
 * reserve the card's height before the bitmap decodes — without them the feed reflows as every
 * image lands.
 *
 * They are read once, off the object, by `CommitUpload` via `ImageSafetyInspector`. Everything
 * after that is just carrying them: `CreateMediaAnalysis::attachUpload()` onto the analysis item,
 * `PublishMediaAnalysis` onto `post_media`. Dropping them at either hop is silent — the columns
 * are nullable and nothing fails — which is exactly why this is pinned.
 */
class MediaDimensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_upload_path_carries_dimensions_onto_the_analysis_item(): void
    {
        Queue::fake();
        $user = $this->trustedUser();
        $upload = $this->uploadedFor($user, width: 1170, height: 2532);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]])
            ->assertAccepted();

        $this->assertDatabaseHas('media_analysis_items', [
            'upload_id' => $upload->id,
            'width' => 1170,
            'height' => 2532,
        ]);
    }

    public function test_dimensions_reach_post_media_when_the_analysis_is_published(): void
    {
        Queue::fake();
        $user = $this->trustedUser();
        $upload = $this->uploadedFor($user, width: 828, height: 1792);
        Sanctum::actingAs($user);

        $token = $this->postJson('/api/v1/media/analyses', ['upload_ids' => [$upload->upload_id]])
            ->assertAccepted()
            ->json('data.token');

        $this->postJson("/api/v1/media/analyses/{$token}/publish")->assertCreated();

        $post = Post::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('post_media', [
            'post_id' => $post->id,
            'width' => 828,
            'height' => 1792,
        ]);
    }

    /**
     * Trusted so the sampler resolves synchronously and the analysis is publishable without
     * running the OCR job — the trust loop itself is OcrTrustPipelineTest's subject, not this
     * test's. Pinned rather than seeded because a trusted account is still spot-checked.
     */
    private function trustedUser(): User
    {
        $user = User::factory()->create();
        UserOcrTrust::create([
            'user_id' => $user->id,
            'trust_tier' => UserOcrTrust::TIER_TRUSTED,
            'consecutive_verified_count' => 25,
        ]);

        $sampler = Mockery::mock(OcrTrustSampler::class);
        $sampler->shouldReceive('shouldSample')->andReturn(false);
        $sampler->shouldReceive('recordVerification')->andReturnNull();
        $this->instance(OcrTrustSampler::class, $sampler);

        return $user;
    }

    private function uploadedFor(User $user, int $width, int $height): Upload
    {
        return Upload::create([
            'upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'object_key' => 'screenshots/'.$user->id.'/'.Str::uuid().'.jpg',
            'nonce' => Str::random(43),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
            'width' => $width,
            'height' => $height,
            'ocr_text' => 'ordinary screen with no sensitive content at all',
            'status' => Upload::STATUS_UPLOADED,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
