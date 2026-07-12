<?php

namespace App\Actions\Posts;

use App\Contracts\MediaFileStore;
use App\Data\Posts\CreatePostData;
use App\Data\Posts\StagedPostBatch;
use App\Data\Posts\StagedPostMedia;
use App\Enums\MediaCleanupStatus;
use App\Models\MediaCleanupTask;
use App\Services\ImageProcessingService;
use Illuminate\Support\Str;
use Throwable;

class StagePostMedia
{
    public function __construct(
        private readonly ImageProcessingService $images,
        private readonly MediaFileStore $files,
    ) {}

    public function __invoke(CreatePostData $data): StagedPostBatch
    {
        $directory = 'posts/'.Str::uuid();
        $task = MediaCleanupTask::create([
            'directory' => $directory,
            'status' => MediaCleanupStatus::Pending,
            'available_at' => now()->addMinutes((int) config('social.media_cleanup_grace_minutes', 60)),
        ]);
        $staged = [];

        try {
            foreach ($data->images as $position => $image) {
                $stored = $this->images->storeOriginal($image, $directory);
                $staged[] = new StagedPostMedia(
                    position: $position,
                    path: $stored['path'],
                    width: $stored['width'],
                    height: $stored['height'],
                    mimeType: $stored['mime'],
                    sizeBytes: $stored['size'],
                );
            }
        } catch (Throwable $exception) {
            $this->cleanup(new StagedPostBatch($task->id, $directory, $staged));

            throw $exception;
        }

        return new StagedPostBatch($task->id, $directory, $staged);
    }

    public function cleanup(StagedPostBatch $staged): void
    {
        try {
            $this->files->deleteDirectory($staged->directory);
            MediaCleanupTask::query()->whereKey($staged->cleanupTaskId)->delete();
        } catch (Throwable $cleanupException) {
            try {
                MediaCleanupTask::query()->whereKey($staged->cleanupTaskId)->update([
                    'status' => MediaCleanupStatus::Failed,
                    'attempts' => 1,
                    'available_at' => now(),
                    'last_error' => Str::limit($cleanupException::class.': '.$cleanupException->getMessage(), 2000, ''),
                ]);
            } catch (Throwable $stateException) {
                report($stateException);
            }

            report($cleanupException);
        }
    }
}
