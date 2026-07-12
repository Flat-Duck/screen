<?php

namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Data\Posts\StagedPostMedia;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates a post + its media rows atomically, then dispatches one thumbnail job per
 * image after commit. The post is immediately visible/servable (via each media's
 * already-EXIF-stripped original) — status=processing only gates the thumbnail.
 */
class CreatePost
{
    public function __construct(
        private readonly StagePostMedia $stageMedia,
        private readonly SyncPostHashtags $syncHashtags,
    ) {}

    public function __invoke(User $user, CreatePostData $data): Post
    {
        $batch = ($this->stageMedia)($data);

        try {
            return DB::transaction(function () use ($user, $data, $batch): Post {
                $post = Post::create([
                    'user_id' => $user->id,
                    'caption' => $data->caption,
                    'status' => Post::STATUS_PROCESSING,
                ]);

                ($this->syncHashtags)($post, $data->caption);

                foreach ($batch->media as $media) {
                    $row = $this->createMediaRow($post, $media);
                    GeneratePostMediaThumbnail::dispatch($row->id)->afterCommit();
                }

                DB::table('media_cleanup_tasks')->where('id', $batch->cleanupTaskId)->delete();

                return $post->load('media');
            });
        } catch (Throwable $exception) {
            $this->stageMedia->cleanup($batch);

            throw $exception;
        }
    }

    private function createMediaRow(Post $post, StagedPostMedia $media): PostMedia
    {
        return $post->media()->create([
            'position' => $media->position,
            'original_path' => $media->path,
            'width' => $media->width,
            'height' => $media->height,
            'mime_type' => $media->mimeType,
            'size_bytes' => $media->sizeBytes,
            'status' => PostMedia::STATUS_PENDING,
        ]);
    }
}
