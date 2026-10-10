<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Central catalog, cached configuration and idempotent point crediting. */
final class PointRewardService
{
    public const FIRST_POST = 'first_post';

    public const FIRST_PRIVATE_SAVE = 'first_private_save';

    public const INVITEE_REGISTRATION = 'invitee_registration';

    public const INVITER_REFERRAL = 'inviter_referral';

    public const ACCOUNT_REGISTRATION = 'account_registration';

    public const DAILY_LOGIN = 'daily_login';

    /** @return array<string, array{label: string, description: string, default_points: int, default_enabled: bool}> */
    public static function catalog(): array
    {
        return [
            self::INVITEE_REGISTRATION => ['label' => 'Register with an invitation', 'description' => 'Award the new member after email verification.', 'default_points' => 50, 'default_enabled' => true],
            self::INVITER_REFERRAL => ['label' => 'Successful invitation', 'description' => 'Award the inviter after the existing maturity window.', 'default_points' => 50, 'default_enabled' => true],
            self::FIRST_POST => ['label' => 'First screenshot post', 'description' => 'Award once after the member publishes their first post.', 'default_points' => 30, 'default_enabled' => true],
            self::FIRST_PRIVATE_SAVE => ['label' => 'First private screenshot save', 'description' => 'Award once after the member saves their first private screenshot.', 'default_points' => 30, 'default_enabled' => true],
            self::ACCOUNT_REGISTRATION => ['label' => 'Verified account registration', 'description' => 'Award once after email verification.', 'default_points' => 0, 'default_enabled' => false],
            self::DAILY_LOGIN => ['label' => 'Daily login', 'description' => 'Award at most once per member per day.', 'default_points' => 0, 'default_enabled' => false],
        ];
    }

    /** @return array{enabled: bool, points: int} */
    public function configuration(string $action): array
    {
        $catalog = self::catalog();
        if (! isset($catalog[$action])) {
            throw new InvalidArgumentException('Unknown point reward action.');
        }

        return Cache::rememberForever($this->cacheKey($action), function () use ($action, $catalog): array {
            $flag = FeatureFlag::query()->where('key', $this->flagKey($action))->first();
            if ($flag === null) {
                $defaults = $catalog[$action];
                if (in_array($action, [self::INVITEE_REGISTRATION, self::INVITER_REFERRAL], true)) {
                    $legacyPayload = FeatureFlag::query()
                        ->where('key', 'registration.invite_only')
                        ->first()?->payload ?? [];
                    $legacyAmount = $legacyPayload['points_per_invite'] ?? null;
                    if ($legacyAmount !== null) {
                        $defaults['default_points'] = max(0, min(100000, (int) $legacyAmount));
                    }
                }

                return ['enabled' => $defaults['default_enabled'], 'points' => $defaults['default_points']];
            }

            return [
                'enabled' => $flag->isActive(),
                'points' => max(0, min(100000, (int) ($flag->payload['points'] ?? 0))),
            ];
        });
    }

    public function invalidate(string $action): void
    {
        Cache::forget($this->cacheKey($action));
    }

    /**
     * Award a configured reward once for the supplied stable event key. Unique DB enforcement
     * protects concurrent requests; the user row lock keeps the balance and ledger atomic.
     */
    public function awardOnce(User $user, string $action, string $idempotencyKey): ?PointTransaction
    {
        $config = $this->configuration($action);
        if (! $config['enabled'] || $config['points'] < 1) {
            return null;
        }

        return DB::transaction(function () use ($user, $action, $idempotencyKey, $config): ?PointTransaction {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());
            if ($lockedUser === null) {
                return null;
            }

            $existing = PointTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $transaction = PointTransaction::query()->create([
                'user_id' => $lockedUser->getKey(),
                'amount' => $config['points'],
                'reason' => $action,
                'idempotency_key' => $idempotencyKey,
            ]);
            $lockedUser->increment('points_balance', $config['points']);

            return $transaction;
        });
    }

    public function flagKey(string $action): string
    {
        if (! array_key_exists($action, self::catalog())) {
            throw new InvalidArgumentException('Unknown point reward action.');
        }

        return 'points.reward.'.$action;
    }

    private function cacheKey(string $action): string
    {
        return 'points.reward.config.'.$action;
    }
}
