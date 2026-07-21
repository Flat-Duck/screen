<?php

namespace App\Actions\Media;

use App\Contracts\MediaFileStore;
use App\Data\Maintenance\PruneSummary;
use App\Enums\MediaCleanupStatus;
use App\Models\MediaAnalysis;
use App\Models\MediaCleanupTask;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

final class CleanOrphanedMedia
{
    public function __construct(private readonly MediaFileStore $files) {}

    public function __invoke(): PruneSummary
    {
        $cleaned = 0;
        $referenced = 0;
        $failed = 0;

        foreach (MediaCleanupTask::query()->where('available_at', '<=', now())->select('id')->lazyById(100) as $candidate) {
            $task = MediaCleanupTask::find($candidate->id);

            if (! $task) {
                continue;
            }

            $analysis = MediaAnalysis::query()->where('cleanup_task_id', $task->id)->first();

            $isReferenced = PostMedia::query()->where('original_path', 'like', $task->directory.'/%')->exists()
                || User::withTrashed()->where('avatar_path', 'like', $task->directory.'/%')->exists();

            if ($isReferenced) {
                $task->delete();
                $referenced++;

                continue;
            }

            try {
                $this->files->deleteDirectory($task->directory);
                $analysis?->delete();
                $task->delete();
                $cleaned++;
            } catch (Throwable $exception) {
                $task->forceFill([
                    'status' => MediaCleanupStatus::Failed,
                    'attempts' => $task->attempts + 1,
                    'available_at' => now()->addMinutes(min(60, 2 ** min($task->attempts, 5))),
                    'last_error' => Str::limit($exception::class.': '.$exception->getMessage(), 2000, ''),
                ])->save();
                $failed++;
                report($exception);
            }
        }

        return new PruneSummary(purged: $cleaned, alreadyGone: $referenced, failed: $failed);
    }
}
