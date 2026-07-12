<?php

namespace App\Services\Storage;

use App\Contracts\MediaFileStore;
use RuntimeException;

class LaravelMediaFileStore implements MediaFileStore
{
    public function deletePaths(array $paths): void
    {
        $disk = app('filesystem')->disk(config('social.media_disk'));

        foreach (array_values(array_unique(array_filter($paths))) as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            if (! $disk->delete($path)) {
                throw new RuntimeException("Failed to delete media file [{$path}].");
            }
        }
    }
}
