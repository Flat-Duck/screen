<?php

namespace App\Jobs;

use App\Contracts\ScreenshotTextExtractor;
use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ExtractPostMediaText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $postMediaId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(ScreenshotTextExtractor $extractor): void
    {
        $media = PostMedia::find($this->postMediaId);
        if (! $media || ($media->ocr_status === PostMedia::PROCESSING_READY && $media->ocr_version === $extractor->version())) {
            return;
        }

        $media->update(['ocr_status' => PostMedia::PROCESSING_PROCESSING]);
        try {
            $result = $extractor->extract($media->original_path);
        } catch (Throwable) {
            // A provider exception may contain recognized text. Replace it before the
            // queue serializes the failure into failed_jobs or telemetry.
            throw new RuntimeException('OCR extraction failed.');
        }
        $text = mb_substr(str_replace("\0", '', $result->text), 0, (int) config('social.processing.ocr.max_characters', 50_000));

        $media->update([
            'ocr_text' => $text === '' ? null : $text,
            'ocr_language' => $result->language,
            'ocr_status' => PostMedia::PROCESSING_READY,
            'ocr_version' => $extractor->version(),
        ]);

        EvaluateScreenshotSafety::dispatch($media->id);
    }

    public function failed(Throwable $exception): void
    {
        PostMedia::query()->whereKey($this->postMediaId)->update([
            'ocr_status' => PostMedia::PROCESSING_FAILED,
            'safety_status' => PostMedia::PROCESSING_FAILED,
        ]);
    }
}
