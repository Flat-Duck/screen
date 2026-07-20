<?php

namespace App\Services;

use App\Enums\AccountVisibility;
use App\Enums\InteractionAudience;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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
                'push_enabled' => true,
                'likes' => true,
                'comments' => true,
                'replies' => true,
                'follows' => true,
                'mentions' => true,
                'reposts' => true,
                'messages' => true,
                'message_requests' => true,
                'follow_requests' => true,
                'product_updates' => false,
                'quiet_hours' => [
                    'enabled' => false,
                    'start' => '22:00',
                    'end' => '07:00',
                    'timezone' => 'UTC',
                ],
            ],
            'privacy' => [
                'account_visibility' => AccountVisibility::Public->value,
            ],
            'interactions' => [
                'comments_from' => InteractionAudience::Everyone->value,
                'mentions_from' => InteractionAudience::Everyone->value,
                // Keep existing mobile behavior until Milestone 1.3 can route strangers
                // into message requests instead of rejecting them outright.
                'messages_from' => InteractionAudience::Everyone->value,
                'reposts_from' => InteractionAudience::Everyone->value,
                'reposts_allowed' => true,
            ],
            'content_filters' => [
                'hide_offensive_comments' => false,
                'hide_offensive_messages' => false,
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
        $settings = $this->mergeOneLevelDeep($this->defaults(), $user->settings ?? []);
        $storedQuietHours = $user->settings['notifications']['quiet_hours'] ?? [];
        $settings['notifications']['quiet_hours'] = array_replace(
            $this->defaults()['notifications']['quiet_hours'],
            is_array($storedQuietHours) ? $storedQuietHours : [],
        );
        $settings['privacy']['account_visibility'] = $user->account_visibility->value;

        return $settings;
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
        if (isset($data['privacy']['account_visibility'])) {
            $user->account_visibility = AccountVisibility::from($data['privacy']['account_visibility']);
            unset($data['privacy']['account_visibility']);
        }

        if (isset($data['notifications']['quiet_hours'])) {
            $currentQuietHours = $this->getFor($user)['notifications']['quiet_hours'];
            $data['notifications']['quiet_hours'] = array_replace($currentQuietHours, $data['notifications']['quiet_hours']);
        }

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

    public function pushNotificationsEnabledFor(User $user, string $key, ?CarbonInterface $now = null): bool
    {
        $settings = $this->getFor($user);

        if (! (bool) ($settings['notifications']['push_enabled'] ?? true)
            || ! (bool) ($settings['notifications'][$key] ?? true)) {
            return false;
        }

        $quiet = $settings['notifications']['quiet_hours'] ?? [];
        if (! (bool) ($quiet['enabled'] ?? false)) {
            return true;
        }

        $timezone = (string) ($quiet['timezone'] ?? 'UTC');
        $local = ($now ?? now())->copy()->setTimezone($timezone);
        $start = Carbon::createFromFormat('H:i', (string) $quiet['start'], $timezone)->setDate($local->year, $local->month, $local->day);
        $end = Carbon::createFromFormat('H:i', (string) $quiet['end'], $timezone)->setDate($local->year, $local->month, $local->day);

        if ($start->equalTo($end)) {
            return true;
        }

        $inQuietHours = $start->lessThan($end)
            ? $local->greaterThanOrEqualTo($start) && $local->lessThan($end)
            : $local->greaterThanOrEqualTo($start) || $local->lessThan($end);

        return ! $inQuietHours;
    }
}
