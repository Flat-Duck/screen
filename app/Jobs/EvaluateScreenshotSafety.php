<?php

namespace App\Jobs;

use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class EvaluateScreenshotSafety implements ShouldQueue
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

    public function handle(ScreenshotSafetyAnalyzer $analyzer): void
    {
        $media = PostMedia::find($this->postMediaId);
        if (! $media || (in_array($media->safety_status, [PostMedia::SAFETY_CLEAR, PostMedia::SAFETY_WARNING], true)
            && $media->safety_version === $analyzer->version())) {
            return;
        }

        try {
            $analysis = $analyzer->analyze($media->ocr_text);
        } catch (Throwable) {
            throw new RuntimeException('Screenshot safety evaluation failed.');
        }

        $media->update([
            'safety_status' => $analysis->hasWarnings()
                ? PostMedia::SAFETY_WARNING
                : PostMedia::SAFETY_CLEAR,
            'safety_version' => $analyzer->version(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        PostMedia::query()->whereKey($this->postMediaId)->update(['safety_status' => PostMedia::PROCESSING_FAILED]);
    }
}
