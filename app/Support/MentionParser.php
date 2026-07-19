<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared @username extraction, used by both SyncPostMentions and SyncCommentMentions —
 * mirrors SyncPostHashtags's regex-extraction shape, but resolves against real users
 * instead of upserting new records (an @mention of a nonexistent username is just ignored,
 * unlike a hashtag which always gets created).
 */
class MentionParser
{
    /** @return Collection<int, User> */
    public function parse(?string $text): Collection
    {
        if (! $text) {
            return collect();
        }

        preg_match_all('/@([a-zA-Z0-9_-]+)/', $text, $matches);

        $usernames = collect($matches[1])->unique()->values();

        if ($usernames->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('username', $usernames)->get();
    }
}
