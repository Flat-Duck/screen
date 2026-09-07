<?php

namespace App\Actions\Media;

use App\Jobs\PublishPostMediaToCdn;
use App\Jobs\UnpublishPostMediaFromCdn;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;

/**
 * Brings a post's — or a whole account's — public CDN copies back in line with whether they should
 * exist, and is the only thing the transition sites need to call.
 *
 * Every path out of "public" has to trigger this: archiving, deleting, purging, going private,
 * deactivation, moderation, account deletion. Centralised so those sites do not each repeat a
 * dispatch loop and quietly diverge — and so there is one place to read to know what the rule is.
 *
 * Safe to call unconditionally: it no-ops when the feature is off, and the jobs themselves re-check
 * before doing anything. `media:reconcile-public` is the backstop for a site that forgets entirely.
 */
class SyncPublicMedia
{
    /** Republishes or unpublishes every media item on one post, per its current visibility. */
    public function forPost(Post $post): void
    {
        if (! config('social.public_cdn.enabled')) {
            return;
        }

        $shouldBePublic = $post->isPubliclyCacheable();

        PostMedia::query()
            ->where('post_id', $post->getKey())
            ->select(['id', 'public_path', 'thumbnail_path'])
            ->get()
            ->each(function (PostMedia $media) use ($shouldBePublic): void {
                if ($shouldBePublic) {
                    // Nothing to do for media whose thumbnail has not been generated yet — the
                    // thumbnail job publishes it itself once both variants exist.
                    if ($media->public_path === null && $media->thumbnail_path !== null) {
                        PublishPostMediaToCdn::dispatch($media->id);
                    }

                    return;
                }

                if ($media->public_path !== null) {
                    UnpublishPostMediaFromCdn::dispatch($media->id);
                }
            });
    }

    /**
     * The same for every post an account owns — for visibility, deactivation and moderation
     * changes, which flip the answer for all of them at once.
     */
    public function forUser(User $user): void
    {
        if (! config('social.public_cdn.enabled')) {
            return;
        }

        Post::withoutGlobalScopes()
            ->withTrashed()
            ->where('user_id', $user->getKey())
            // Every column isPubliclyCacheable() reads, plus the author it reads them alongside.
            // Selecting less would throw under Model::shouldBeStrict() rather than silently
            // returning the wrong answer, which is the point of that setting.
            ->select(['id', 'user_id', 'archived_at', 'deleted_at'])
            ->with('user')
            ->chunkById(200, function ($posts): void {
                foreach ($posts as $post) {
                    $this->forPost($post);
                }
            });
    }
}
