<?php

namespace App\Actions\Posts;

use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates a post + its media rows atomically, then dispatches one thumbnail job per
 * image after commit. The post is immediately visible/servable (via each media's
 * already-EXIF-stripped original) — status=processing only gates the thumbnail.
 */
class CreatePost
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /** @param  array<string, mixed>  $data  Validated StorePostRequest data: 'caption' and 'images'. */
    public function __invoke(User $user, array $data): Post
    {
        $post = DB::transaction(function () use ($user, $data) {
            $post = Post::create([
                'user_id' => $user->id,
                'caption' => $data['caption'] ?? null,
                'status' => Post::STATUS_PROCESSING,
            ]);

            $this->syncHashtags($post, $data['caption'] ?? null);

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
     * Extracts `#word` tokens from the caption (Unicode-aware, so Arabic hashtags work
     * too — this app is bilingual) and links them to the post. There's no post-edit
     * endpoint, so this only ever needs to run once, at creation.
     */
    private function syncHashtags(Post $post, ?string $caption): void
    {
        if (! $caption) {
            return;
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        $names = collect($matches[1])
            ->map(fn (string $tag): string => Hashtag::normalize($tag))
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $hashtagIds = $names->map(
            fn (string $name): int => Hashtag::query()->firstOrCreate(['name' => $name])->id
        );

        $post->hashtags()->sync($hashtagIds);
    }
}
