<?php

namespace App\Actions\Posts;

use App\Models\Post;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes a (soft-deleted) post: deletes each media's files from disk, then
 * force-deletes the DB rows. The FK's cascade delete stays only as a referential-integrity
 * backstop, since a raw cascade wouldn't fire Eloquent events and would leak orphaned files.
 *
 * Only called by the scheduled prune commands — never directly from a controller.
 */
class PurgePost
{
    public function __invoke(Post $post): void
    {
        $disk = Storage::disk(config('social.media_disk'));

        foreach ($post->media as $media) {
            $disk->delete(array_values(array_filter([$media->original_path, $media->thumbnail_path])));
        }

        $post->forceDelete();
    }
}
