<?php

namespace App\Jobs;

use App\Contracts\MediaFileStore;
use App\Enums\MediaCleanupStatus;
use App\Exceptions\PermanentRemoteImageException;
use App\Models\MediaCleanupTask;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ImportSocialAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $userId, public readonly string $url)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(ImageProcessingService $images, MediaFileStore $files): void
    {
        $user = User::find($this->userId);

        if (! $user || $user->avatar_path !== null) {
            return;
        }

        $directory = "avatars/{$user->id}/imports/".Str::uuid();
        $cleanup = MediaCleanupTask::create([
            'directory' => $directory,
            'status' => MediaCleanupStatus::Pending,
            'available_at' => now()->addMinutes((int) config('social.media_cleanup_grace_minutes', 60)),
        ]);

        try {
            $stored = $images->storeFromUrl($this->url, $directory);
        } catch (PermanentRemoteImageException $exception) {
            $cleanup->delete();
            report($exception);

            return;
        }

        try {
            $updated = User::query()
                ->whereKey($user->id)
                ->whereNull('avatar_path')
                ->update(['avatar_path' => $stored['path']]);
        } catch (Throwable $exception) {
            try {
                $files->deleteDirectory($directory);
                $cleanup->delete();
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        if ($updated === 1) {
            try {
                $cleanup->delete();
            } catch (Throwable $exception) {
                report($exception);
            }

            return;
        }

        try {
            $files->deleteDirectory($directory);
            $cleanup->delete();
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }
}
