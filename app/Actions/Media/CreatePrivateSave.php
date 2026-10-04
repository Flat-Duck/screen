<?php

namespace App\Actions\Media;

use App\Enums\MediaCleanupStatus;
use App\Models\MediaCleanupTask;
use App\Models\PrivateSave;
use App\Models\PrivateSaveFolder;
use App\Models\User;
use App\Services\ImageProcessingService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePrivateSave
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /**
     * Call outside the request transaction so the cleanup reservation survives rollback.
     * The optional callback commits authoritative analytics in the same transaction as the save.
     *
     * @param  Closure(PrivateSave): void|null  $afterPersist
     */
    public function __invoke(User $user, UploadedFile $image, ?PrivateSaveFolder $folder = null, ?Closure $afterPersist = null): PrivateSave
    {
        $directory = 'private-saves/'.$user->id.'/'.Str::uuid();
        $disk = (string) config('social.private_media_disk', 'local');
        $cleanup = MediaCleanupTask::create([
            'directory' => $directory,
            'source_disk' => $disk,
            'status' => MediaCleanupStatus::Pending,
            'available_at' => now()->addMinutes((int) config('social.media_cleanup_grace_minutes', 60)),
        ]);
        // Register first: a crash, failed write or failed commit leaves a durable cleanup task.
        $stored = $this->images->storeOriginal($image, $directory, diskName: $disk);

        return DB::transaction(function () use ($user, $folder, $stored, $disk, $cleanup, $afterPersist): PrivateSave {
            $save = PrivateSave::create([
                'user_id' => $user->id,
                'folder_id' => $folder?->getKey(),
                'path' => $stored['path'],
                'source_disk' => $disk,
                'width' => $stored['width'],
                'height' => $stored['height'],
                'mime_type' => $stored['mime'],
                'size_bytes' => $stored['size'],
            ]);
            $afterPersist?->__invoke($save);
            // Deletion rolls back with the save on any failure, including commit failure.
            $cleanup->delete();

            return $save;
        });
    }
}
