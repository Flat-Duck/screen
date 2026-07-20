<?php

namespace App\Services;

use App\Enums\InteractionAudience;
use App\Models\User;

class InteractionPermissionService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly BlockService $blocks,
    ) {}

    public function canComment(User $actor, User $recipient): bool
    {
        return $this->allows($actor, $recipient, 'comments_from');
    }

    public function canMention(User $actor, User $recipient): bool
    {
        return $this->allows($actor, $recipient, 'mentions_from');
    }

    public function canMessage(User $actor, User $recipient): bool
    {
        return $this->allows($actor, $recipient, 'messages_from');
    }

    public function canRepost(User $actor, User $recipient): bool
    {
        $interactions = $this->settings->getFor($recipient)['interactions'];

        return (bool) ($interactions['reposts_allowed'] ?? true)
            && $this->allows($actor, $recipient, 'reposts_from');
    }

    private function allows(User $actor, User $recipient, string $setting): bool
    {
        if ($actor->is($recipient)) {
            return true;
        }

        if ($this->blocks->isBlockedEitherWay($actor, $recipient)) {
            return false;
        }

        $value = $this->settings->getFor($recipient)['interactions'][$setting]
            ?? InteractionAudience::Everyone->value;
        $audience = InteractionAudience::from((string) $value);

        if ($audience === InteractionAudience::Everyone) {
            return true;
        }

        if ($audience === InteractionAudience::NoOne) {
            return false;
        }

        $actorFollowsRecipient = $actor->following()->where('followee_id', $recipient->id)->exists();
        $recipientFollowsActor = $recipient->following()->where('followee_id', $actor->id)->exists();

        return match ($audience) {
            InteractionAudience::Followers => $actorFollowsRecipient,
            InteractionAudience::Following => $recipientFollowsActor,
            default => $actorFollowsRecipient && $recipientFollowsActor,
        };
    }
}
