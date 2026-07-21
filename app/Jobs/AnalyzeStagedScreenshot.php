<?php

namespace App\Jobs;

use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Contracts\ScreenshotTextExtractor;
use App\Models\MediaAnalysis;
use App\Models\MediaAnalysisItem;
use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class AnalyzeStagedScreenshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $itemId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(ScreenshotTextExtractor $extractor, ScreenshotSafetyAnalyzer $analyzer): void
    {
        $item = MediaAnalysisItem::with('analysis')->find($this->itemId);
        if (! $item || $item->analysis->isExpired()
            || ($item->ocr_status === PostMedia::PROCESSING_READY && $item->analysis_version === $this->version($extractor, $analyzer))) {
            return;
        }

        $item->update(['ocr_status' => PostMedia::PROCESSING_PROCESSING]);
        try {
            $ocr = $extractor->extract($item->original_path);
            $text = mb_substr(str_replace("\0", '', $ocr->text), 0, (int) config('social.processing.ocr.max_characters', 50_000));
            $safety = $analyzer->analyze($text);
        } catch (Throwable) {
            throw new RuntimeException('Staged screenshot analysis failed.');
        }

        $item->update([
            'ocr_text' => $text === '' ? null : $text,
            'ocr_language' => $ocr->language,
            'ocr_status' => PostMedia::PROCESSING_READY,
            'safety_status' => $safety->hasWarnings() ? PostMedia::SAFETY_WARNING : PostMedia::SAFETY_CLEAR,
            'analysis_version' => $this->version($extractor, $analyzer),
            'findings' => $safety->findings,
        ]);
        $this->syncAnalysis($item->analysis);
    }

    public function failed(Throwable $exception): void
    {
        $item = MediaAnalysisItem::with('analysis')->find($this->itemId);
        if (! $item) {
            return;
        }
        $item->update(['ocr_status' => PostMedia::PROCESSING_FAILED, 'safety_status' => PostMedia::PROCESSING_FAILED]);
        $item->analysis->update(['status' => MediaAnalysis::STATUS_FAILED]);
    }

    private function syncAnalysis(MediaAnalysis $analysis): void
    {
        if ($analysis->items()->where('ocr_status', '!=', PostMedia::PROCESSING_READY)->doesntExist()) {
            $analysis->update(['status' => MediaAnalysis::STATUS_READY]);
        }
    }

    private function version(ScreenshotTextExtractor $extractor, ScreenshotSafetyAnalyzer $analyzer): string
    {
        return $extractor->version().'+'.$analyzer->version();
    }
}
