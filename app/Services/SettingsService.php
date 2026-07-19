<?php

namespace App\Services;

use App\Models\User;

/**
 * A single `users.settings` JSON column backs every future user-configurable setting —
 * a generic `GET`/`PATCH /v1/settings` shallow-merged by top-level key, rather than a
 * dedicated column (and endpoint) per toggle. `notifications` is the first real key;
 * see FcmChannel for how it gates push delivery. Database/in-app notifications are
 * deliberately never gated by this — only push (see FcmChannel::send()).
 */
class SettingsService
{
    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'notifications' => [
                'likes' => true,
                'comments' => true,
                'follows' => true,
                'mentions' => true,
                'reposts' => true,
                'messages' => true,
            ],
        ];
    }

    /**
     * Layers the stored (possibly partial) settings over the defaults — a user who's
     * only ever flipped `notifications.likes` still gets `comments`/`follows` back as
     * `true` here, not missing/null, since only `likes` was ever actually persisted.
     *
     * @return array<string, mixed>
     */
    public function getFor(User $user): array
    {
        return $this->mergeOneLevelDeep($this->defaults(), $user->settings ?? []);
    }

    /**
     * Merged by top-level key (`notifications`, and whatever gets added alongside it
     * later) — a `PATCH` touching one top-level key never disturbs a sibling one. Within
     * a top-level key, one more level merges too when both sides are arrays (so
     * `{"notifications": {"likes": false}}` only flips `likes`, leaving `comments`/
     * `follows` at whatever they were) — intentionally not a deep merge past that,
     * to keep this generic column simple as more top-level keys get added over time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, array $data): array
    {
        $user->settings = $this->mergeOneLevelDeep($user->settings ?? [], $data);
        $user->save();

        return $this->getFor($user);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeOneLevelDeep(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? array_replace($base[$key], $value)
                : $value;
        }

        return $base;
    }

    public function pushNotificationsEnabledFor(User $user, string $key): bool
    {
        $settings = $this->getFor($user);

        return (bool) ($settings['notifications'][$key] ?? true);
    }
}
