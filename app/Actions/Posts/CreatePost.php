<?php

namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Data\Posts\StagedPostMedia;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\ExtractPostMediaText;
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
        private readonly SyncPostMentions $syncMentions,
    ) {}

    public function __invoke(User $user, CreatePostData $data): Post
    {
        $batch = ($this->stageMedia)($data);

        try {
            $post = DB::transaction(function () use ($user, $data, $batch): Post {
                $post = Post::create([
                    'user_id' => $user->id,
                    'caption' => $data->caption,
                    'status' => Post::STATUS_PROCESSING,
                    'comments_enabled' => $data->commentsEnabled,
                    'reposts_enabled' => $data->repostsEnabled,
                    'category_id' => $data->categoryId,
                    'source_application' => $data->sourceApplication,
                    'source_url' => $data->sourceUrl,
                    'content_warning' => $data->contentWarning,
                ]);

                ($this->syncHashtags)($post, $data->caption);

                foreach ($batch->media as $media) {
                    $row = $this->createMediaRow($post, $media, $data->mediaMetadata[$media->position] ?? []);
                    GeneratePostMediaThumbnail::dispatch($row->id)->afterCommit();
                }

                DB::table('media_cleanup_tasks')->where('id', $batch->cleanupTaskId)->delete();

                return $post->load('media');
            });

            // Outside the transaction, not inside like syncHashtags — mentions can fire a
            // queued notification, and this app's queue connections don't defer dispatch
            // until commit (config/queue.php's after_commit => false), so a worker could
            // otherwise pick the job up before the post/mention rows are actually visible.
            ($this->syncMentions)($post, $data->caption);

            foreach ($post->media as $media) {
                ExtractPostMediaText::dispatch($media->id);
                ComputePostMediaPerceptualHash::dispatch($media->id);
            }

            return $post;
        } catch (Throwable $exception) {
            $this->stageMedia->cleanup($batch);

            throw $exception;
        }
    }

    /** @param array{alt_text?: string|null} $metadata */
    private function createMediaRow(Post $post, StagedPostMedia $media, array $metadata): PostMedia
    {
        return $post->media()->create([
            'position' => $media->position,
            'original_path' => $media->path,
            'width' => $media->width,
            'height' => $media->height,
            'mime_type' => $media->mimeType,
            'size_bytes' => $media->sizeBytes,
            'status' => PostMedia::STATUS_PENDING,
            'alt_text' => $metadata['alt_text'] ?? null,
        ]);
    }
}
