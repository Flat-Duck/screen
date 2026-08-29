<?php

namespace App\Http\Controllers;

use App\Enums\AccountVisibility;
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

        $disk = Storage::disk($postMedia->sourceDisk());
        abort_unless($disk->exists($path), 404);

        return MediaDelivery::respond($disk, $path, $this->cacheControl($post), [
            'Content-Type' => $mimeType,
        ]);
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
        $publiclyCacheable = ! $post->trashed()
            && $post->archived_at === null
            && $post->user->account_visibility === AccountVisibility::Public
            && $post->user->isPubliclyVisible();

        if (! $publiclyCacheable) {
            return 'no-store, private';
        }

        $seconds = min(
            (int) config('social.public_media_cache_seconds', 300),
            (int) config('social.media_url_ttl_seconds', 1200),
        );

        return 'public, max-age='.max(0, $seconds).', must-revalidate';
    }
}
