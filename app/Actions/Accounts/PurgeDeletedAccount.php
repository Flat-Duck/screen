<?php

namespace App\Actions\Accounts;

use App\Actions\Posts\PurgePost;
use App\Contracts\MediaFileStore;
use App\Enums\AccountPurgeOutcome;
use App\Enums\PostPurgeOutcome;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class PurgeDeletedAccount
{
    public function __construct(
        private readonly PurgePost $purgePost,
        private readonly MediaFileStore $files,
    ) {}

    public function __invoke(int $userId): AccountPurgeOutcome
    {
        $lock = Cache::lock("account-purge:{$userId}", 600);

        if (! $lock->get()) {
            return AccountPurgeOutcome::Busy;
        }

        try {
            $user = User::onlyTrashed()->find($userId);

            if (! $user) {
                return AccountPurgeOutcome::AlreadyGone;
            }

            foreach ($user->posts()->withoutGlobalScope(NotArchivedScope::class)->onlyTrashed()->select('posts.id')->lazyById(100) as $post) {
                if (($this->purgePost)($post->id) === PostPurgeOutcome::Busy) {
                    return AccountPurgeOutcome::Busy;
                }
            }

            $this->files->deletePaths($user->avatar_path ? [$user->avatar_path] : []);
            $user->notifications()->delete();
            $user->tokens()->delete();
            $user->forceDelete();

            return AccountPurgeOutcome::Purged;
        } finally {
            $lock->release();
        }
    }
}
