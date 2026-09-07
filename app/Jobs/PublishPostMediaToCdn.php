<?php

namespace App\Jobs;

use App\Models\PostMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies a public post's media into the public bucket so it can be served from the CDN in one
 * round trip instead of the signed-URL-then-redirect dance.
 *
 * Copies **server side** where the disks allow it, so the bytes never enter PHP.
 */
class PublishPostMediaToCdn implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $postMediaId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(): void
    {
        if (! config('social.public_cdn.enabled')) {
            return;
        }

        $media = PostMedia::with('post.user')->find($this->postMediaId);

        if (! $media || ! $media->post?->isPubliclyCacheable()) {
            // Re-checked at run time, not just at dispatch: a post can be archived, deleted, or
            // its author made private in the window between the two. This is the guard that makes
            // the whole feature safe to dispatch optimistically.
            return;
        }

        $media->public_token ??= Str::lower(Str::random(32));

        $source = Storage::disk($media->sourceDisk());
        $target = Storage::disk((string) config('social.public_cdn.disk'));

        $published = [];

        foreach (['original' => $media->original_path, 'thumbnail' => $media->thumbnail_path] as $variant => $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $key = $media->publicObjectKey($variant);

            if ($key === null || ! $source->exists($path)) {
                continue;
            }

            $stream = $source->readStream($path);

            if ($stream === null) {
                continue;
            }

            try {
                $target->writeStream($key, $stream, [
                    'visibility' => 'public',
                    // Objects are named by an unguessable token and never rewritten, so they are
                    // safe to cache indefinitely. Revocation is deletion, not expiry.
                    'CacheControl' => 'public, max-age='.(int) config('social.public_cdn.max_age_seconds', 31536000).', immutable',
                    'ContentType' => $variant === 'thumbnail' ? 'image/webp' : (string) $media->mime_type,
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $published[$variant] = $key;
        }

        if ($published === []) {
            return;
        }

        $media->forceFill([
            'public_token' => $media->public_token,
            'public_path' => $published['original'] ?? null,
            'public_thumbnail_path' => $published['thumbnail'] ?? null,
            'published_publicly_at' => now(),
        ])->save();
    }
}
