<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * The first Policy in this app gating `view`, not `delete` — every other Policy'd resource
 * (Post, Comment) is public-by-default; a Conversation is private-by-membership from the
 * start, so there's no "read" path that doesn't need authorization.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }
}
