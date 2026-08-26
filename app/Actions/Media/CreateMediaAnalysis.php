<?php

namespace App\Actions\Media;

use App\Contracts\MediaFileStore;
use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Enums\MediaCleanupStatus;
use App\Jobs\AnalyzeStagedScreenshot;
use App\Models\MediaAnalysis;
use App\Models\MediaCleanupTask;
use App\Models\PostMedia;
use App\Models\Upload;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class CreateMediaAnalysis
{
    public function __construct(
        private readonly ImageProcessingService $images,
        private readonly MediaFileStore $files,
        private readonly ScreenshotSafetyAnalyzer $safetyAnalyzer,
        private readonly OcrTrustSampler $sampler,
    ) {}

    /**
     * @param  list<UploadedFile>  $images  Legacy raw-multipart path — bytes flow through this app.
     * @param  list<string>  $uploadIds  New direct-to-R2 path (docs/SECURITY.md §12) — each must be
     *                                   an already-committed Upload owned by $user. Mutually
     *                                   exclusive with $images at the request-validation layer
     *                                   (StoreMediaAnalysisRequest); never both non-empty here.
     */
    public function __invoke(User $user, array $images, array $uploadIds = []): MediaAnalysis
    {
        $token = (string) Str::uuid();
        $directory = 'analyses/'.$token;
        $expiresAt = now()->addMinutes((int) config('social.processing.analysis_ttl_minutes', 30));
        $cleanup = MediaCleanupTask::create([
            'directory' => $directory,
            'status' => MediaCleanupStatus::Pending,
            'available_at' => $expiresAt,
        ]);
        $analysis = MediaAnalysis::create([
            'token' => $token,
            'user_id' => $user->id,
            'cleanup_task_id' => $cleanup->id,
            'directory' => $directory,
            'status' => MediaAnalysis::STATUS_PROCESSING,
            'expires_at' => $expiresAt,
        ]);

        try {
            $toDispatch = [];
            foreach ($images as $position => $image) {
                $stored = $this->images->storeOriginal($image, $directory);
                $item = $analysis->items()->create([
                    'position' => $position,
                    'original_path' => $stored['path'],
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'mime_type' => $stored['mime'],
                    'size_bytes' => $stored['size'],
                ]);
                $toDispatch[] = $item->id;
            }

            foreach ($uploadIds as $offset => $uploadId) {
                $toDispatch = [
                    ...$toDispatch,
                    ...$this->attachUpload($analysis, $user, $uploadId, count($images) + $offset),
                ];
            }

            foreach ($toDispatch as $itemId) {
                AnalyzeStagedScreenshot::dispatch($itemId);
            }
            // Nothing dispatches for a fully-trusted (not-sampled) upload_ids request — without
            // this, such an analysis would stay STATUS_PROCESSING forever, since the job that
            // normally flips it to STATUS_READY never runs for any of its items. See
            // MediaAnalysis::syncStatusIfReady's kdoc.
            $analysis->syncStatusIfReady();
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($directory);
            $analysis->delete();
            $cleanup->delete();
            throw $exception;
        }

        return $analysis->refresh()->load('items');
    }

    /** @return list<int> Item ids that still need AnalyzeStagedScreenshot dispatched (sampled only). */
    private function attachUpload(MediaAnalysis $analysis, User $user, string $uploadId, int $position): array
    {
        $upload = Upload::query()
            ->where('upload_id', $uploadId)
            ->where('user_id', $user->id)
            ->where('status', Upload::STATUS_UPLOADED)
            ->firstOrFail();

        $shared = [
            'position' => $position,
            'original_path' => $upload->object_key,
            'source_disk' => config('social.uploads.disk', 'r2'),
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'upload_id' => $upload->id,
            'ocr_source' => 'device',
        ];

        if ($this->sampler->shouldSample($user, $upload->ocr_text)) {
            $item = $analysis->items()->create([
                ...$shared,
                'device_ocr_text' => $upload->ocr_text,
                'verification_status' => 'pending',
            ]);
            $upload->update(['status' => Upload::STATUS_VERIFIED]);

            return [$item->id];
        }

        // Trusted: the device's claim becomes the item's canonical ocr_text immediately.
        // AnalyzeStagedScreenshot is never dispatched for this item — Tesseract never runs, R2 is
        // never read. Still runs the (cheap, regex-based) safety analyzer synchronously, since
        // there's no reason to skip that just because the text itself is trusted.
        $safety = $this->safetyAnalyzer->analyze($upload->ocr_text);
        $analysis->items()->create([
            ...$shared,
            'ocr_text' => $upload->ocr_text,
            'ocr_status' => PostMedia::PROCESSING_READY,
            'safety_status' => $safety->hasWarnings() ? PostMedia::SAFETY_WARNING : PostMedia::SAFETY_CLEAR,
            'findings' => $safety->findings,
            'verification_status' => 'not_sampled',
            'analysis_version' => $this->safetyAnalyzer->version(),
        ]);
        $upload->update(['status' => Upload::STATUS_VERIFIED]);

        return [];
    }
}
