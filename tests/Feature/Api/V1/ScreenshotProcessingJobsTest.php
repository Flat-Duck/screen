<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\PerceptualHasher;
use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\EvaluateScreenshotSafety;
use App\Jobs\ExtractPostMediaText;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Screenshots\DifferenceHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScreenshotProcessingJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_is_encrypted_at_rest_and_safety_warns_without_exposing_detected_text(): void
    {
        $media = PostMedia::factory()->create();
        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()->andReturn(
            new TextExtractionResult('password = super-secret-value', 'eng'),
        );

        (new ExtractPostMediaText($media->id))->handle($extractor);

        $media->refresh();
        $this->assertSame(PostMedia::PROCESSING_READY, $media->ocr_status);
        $this->assertSame('password = super-secret-value', $media->ocr_text);
        $this->assertSame(PostMedia::SAFETY_WARNING, $media->safety_status);
        $this->assertStringNotContainsString(
            'super-secret-value',
            (string) DB::table('post_media')->where('id', $media->id)->value('ocr_text'),
        );
    }

    public function test_ocr_job_is_idempotent_for_the_current_provider_version(): void
    {
        $media = PostMedia::factory()->create();
        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()->andReturn(new TextExtractionResult('hello', 'eng'));
        $job = new ExtractPostMediaText($media->id);

        $job->handle($extractor);
        $job->handle($extractor);

        $this->assertSame('hello', $media->refresh()->ocr_text);
    }

    public function test_ocr_provider_errors_are_sanitized_and_exhausted_retries_mark_failure(): void
    {
        $media = PostMedia::factory()->create();
        $extractor = Mockery::mock(ScreenshotTextExtractor::class);
        $extractor->allows('version')->andReturn('fake-v1');
        $extractor->shouldReceive('extract')->once()->andThrow(new RuntimeException('recognized password: secret-value'));
        $job = new ExtractPostMediaText($media->id);

        try {
            $job->handle($extractor);
            $this->fail('Expected OCR extraction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OCR extraction failed.', $exception->getMessage());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }

        $job->failed(new RuntimeException('OCR extraction failed.'));
        $media->refresh();
        $this->assertSame(PostMedia::PROCESSING_FAILED, $media->ocr_status);
        $this->assertSame(PostMedia::PROCESSING_FAILED, $media->safety_status);
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff());
    }

    public function test_equal_images_receive_the_same_indexed_perceptual_hash(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('same.png', 400, 800)->getContent();
        Storage::disk('public')->put('posts/one.png', $image);
        Storage::disk('public')->put('posts/two.png', $image);
        $first = PostMedia::factory()->create(['original_path' => 'posts/one.png']);
        $second = PostMedia::factory()->create(['original_path' => 'posts/two.png']);
        $hasher = new DifferenceHashService;

        (new ComputePostMediaPerceptualHash($first->id))->handle($hasher);
        (new ComputePostMediaPerceptualHash($second->id))->handle($hasher);

        $this->assertSame($first->refresh()->perceptual_hash, $second->refresh()->perceptual_hash);
        $this->assertSame(PostMedia::PROCESSING_READY, $first->hash_status);
        $this->assertSame('dhash-64-v1', $first->hash_version);
    }

    public function test_hash_job_is_idempotent_and_records_terminal_failure(): void
    {
        $media = PostMedia::factory()->create();
        $hasher = Mockery::mock(PerceptualHasher::class);
        $hasher->allows('version')->andReturn('fake-hash-v1');
        $hasher->shouldReceive('hash')->once()->andReturn('0123456789abcdef');
        $job = new ComputePostMediaPerceptualHash($media->id);

        $job->handle($hasher);
        $job->handle($hasher);
        $this->assertSame('0123456789abcdef', $media->refresh()->perceptual_hash);

        $failedMedia = PostMedia::factory()->create();
        (new ComputePostMediaPerceptualHash($failedMedia->id))->failed(new RuntimeException('failed'));
        $this->assertSame(PostMedia::PROCESSING_FAILED, $failedMedia->refresh()->hash_status);
    }

    public function test_safety_job_is_idempotent_and_missing_media_is_a_no_op(): void
    {
        $media = PostMedia::factory()->create(['ocr_text' => 'ordinary screenshot text']);
        $job = new EvaluateScreenshotSafety($media->id);
        $analyzer = app(ScreenshotSafetyAnalyzer::class);

        $job->handle($analyzer);
        $job->handle($analyzer);

        $this->assertSame(PostMedia::SAFETY_CLEAR, $media->refresh()->safety_status);
        (new EvaluateScreenshotSafety(999999))->handle($analyzer);
        $this->addToAssertionCount(1);
    }

    public function test_permanent_post_deletion_removes_all_derived_media_data(): void
    {
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create([
            'ocr_text' => 'derived text',
            'perceptual_hash' => '0123456789abcdef',
            'ocr_status' => PostMedia::PROCESSING_READY,
            'hash_status' => PostMedia::PROCESSING_READY,
        ]);

        $post->forceDelete();

        $this->assertDatabaseMissing('post_media', ['id' => $media->id]);
    }
}
