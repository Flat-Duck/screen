<?php

namespace App\Actions\Media;

use App\Contracts\MediaFileStore;
use App\Enums\MediaCleanupStatus;
use App\Jobs\AnalyzeStagedScreenshot;
use App\Models\MediaAnalysis;
use App\Models\MediaCleanupTask;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class CreateMediaAnalysis
{
    public function __construct(
        private readonly ImageProcessingService $images,
        private readonly MediaFileStore $files,
    ) {}

    /**
     * @param  list<UploadedFile>  $images
     * @param  list<array{alt_text?: string|null}>  $metadata
     */
    public function __invoke(User $user, array $images, array $metadata): MediaAnalysis
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
            $itemIds = [];
            foreach ($images as $position => $image) {
                $stored = $this->images->storeOriginal($image, $directory);
                $item = $analysis->items()->create([
                    'position' => $position,
                    'original_path' => $stored['path'],
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'mime_type' => $stored['mime'],
                    'size_bytes' => $stored['size'],
                    'alt_text' => $metadata[$position]['alt_text'] ?? null,
                ]);
                $itemIds[] = $item->id;
            }
            foreach ($itemIds as $itemId) {
                AnalyzeStagedScreenshot::dispatch($itemId);
            }
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($directory);
            $analysis->delete();
            $cleanup->delete();
            throw $exception;
        }

        return $analysis->refresh()->load('items');
    }
}
