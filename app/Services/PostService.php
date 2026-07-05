<?php

namespace App\Services;

use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PostService
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /**
     * Creates the post + its media rows atomically, then dispatches one thumbnail job
     * per image after commit. The post is immediately visible/servable (via each media's
     * already-EXIF-stripped original) — status=processing only gates the thumbnail.
     *
     * @param  array<string, mixed>  $data  Validated StorePostRequest data: 'caption' and 'images'.
     */
    public function createPost(User $user, array $data): Post
    {
        $post = DB::transaction(function () use ($user, $data) {
            $post = Post::create([
                'user_id' => $user->id,
                'caption' => $data['caption'] ?? null,
                'status' => Post::STATUS_PROCESSING,
            ]);

            $images = is_array($data['images'] ?? null) ? $data['images'] : [];

            foreach (array_values($images) as $position => $image) {
                if (! $image instanceof UploadedFile) {
                    continue;
                }

                $stored = $this->images->storeOriginal($image, "posts/{$post->id}");

                $post->media()->create([
                    'position' => $position,
                    'original_path' => $stored['path'],
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'mime_type' => $stored['mime'],
                    'size_bytes' => $stored['size'],
                    'status' => PostMedia::STATUS_PENDING,
                ]);
            }

            return $post;
        });

        $post->load('media');
        $post->media->each(fn (PostMedia $item) => GeneratePostMediaThumbnail::dispatch($item->id));

        return $post;
    }

    /**
     * Deletes each media's files from disk before removing DB rows — the FK's cascade delete
     * stays only as a referential-integrity backstop, since a raw cascade wouldn't fire
     * Eloquent events and would leak orphaned files.
     */
    public function deletePost(Post $post): void
    {
        $disk = Storage::disk(config('social.media_disk'));

        foreach ($post->media as $media) {
            $disk->delete(array_values(array_filter([$media->original_path, $media->thumbnail_path])));
        }

        $post->delete();
    }

    /** @return CursorPaginator<int, Post> */
    public function postsForUser(User $user, int $perPage = 12): CursorPaginator
    {
        return $user->posts()
            ->with('media')
            ->withCount(['likes', 'comments'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }
}
