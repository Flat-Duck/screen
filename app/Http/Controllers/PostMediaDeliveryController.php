<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Services\BlockService;
use App\Support\Media\MediaDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PostMediaDeliveryController extends Controller
{
    public function __construct(private readonly BlockService $blocks) {}

    public function __invoke(Request $request, int $media, string $variant): Response
    {
        $viewer = User::query()->findOrFail((int) $request->query('viewer'));
        $postMedia = PostMedia::query()->findOrFail($media);
        $post = Post::withoutGlobalScope(NotArchivedScope::class)
            ->withTrashed()
            ->with('user')
            ->findOrFail($postMedia->post_id);

        abort_unless($post->user instanceof User, 404);
        $this->authorizeViewer($viewer, $post);

        [$path, $mimeType] = match ($variant) {
            'original' => [$postMedia->original_path, $postMedia->mime_type],
            'thumbnail' => [$postMedia->thumbnail_path, 'image/webp'],
            default => abort(404),
        };

        abort_unless(is_string($path) && $path !== '' && ! str_contains($path, '://'), 404);

        // A signed URL minted before this media was published to the CDN still works, and still
        // reauthorizes here — but there is no reason to stream or presign the private object when
        // an edge-cached public copy exists. Costs those older clients one extra hop, once.
        if (($cdn = $postMedia->publicCdnUrl($variant)) !== null) {
            return redirect()->away($cdn, 302, [
                'Cache-Control' => 'public, max-age='.(int) config('social.public_cdn.max_age_seconds', 31536000),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $disk = Storage::disk($postMedia->sourceDisk());

        return MediaDelivery::respond(
            $disk,
            $path,
            $this->cacheControl($post),
            ['Content-Type' => $mimeType],
            // Published media bytes are immutable per (row, variant) — editing a post never
            // rewrites them — so a strong validator needs no I/O at all. updated_at is in there
            // only so a reprocessed thumbnail invalidates rather than serving the old bytes.
            $this->etag($postMedia, $variant),
        );
    }

    private function etag(PostMedia $media, string $variant): string
    {
        return sprintf('pm-%d-%s-%d', $media->getKey(), $variant, $media->updated_at?->getTimestamp() ?? 0);
    }

    private function authorizeViewer(User $viewer, Post $post): void
    {
        if ($viewer->is($post->user)) {
            return;
        }

        abort_if($post->trashed() || $post->archived_at !== null, 404);
        abort_if($this->blocks->isBlockedEitherWay($viewer, $post->user), 404);
        abort_unless($post->isVisibleTo($viewer), 404);
    }

    private function cacheControl(Post $post): string
    {
        if (! $post->isPubliclyCacheable()) {
            return 'no-store, private';
        }

        $seconds = min(
            (int) config('social.public_media_cache_seconds', 300),
            (int) config('social.media_url_ttl_seconds', 1200),
        );

        return 'public, max-age='.max(0, $seconds).', must-revalidate';
    }
}
