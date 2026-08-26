<?php

namespace App\Services\Screenshots;

use App\Models\User;
use App\Models\UserOcrTrust;

/**
 * Decides whether a device-claimed OCR text gets server-side re-verified (see
 * docs/SECURITY.md §4/§12) — a "new" or "probation" account is always sampled; a "trusted"
 * account is sampled at TRUSTED_SAMPLE_RATE, plus always when the claimed text is blank (cheap
 * to double-check, costs nothing when the account is trusted anyway). Deliberately simple
 * counters, not a weighted trust score — see docs/SECURITY.md §6 on why a summed score lets a bad
 * signal get compensated by unrelated good ones.
 */
class OcrTrustSampler
{
    private const NEW_ACCOUNT_VERIFIED_THRESHOLD = 20;

    private const PROBATION_VERIFIED_THRESHOLD = 20;

    private const TRUSTED_SAMPLE_RATE_PERCENT = 8;

    public function shouldSample(User $user, ?string $deviceOcrText): bool
    {
        $trust = $this->trustFor($user);

        if ($trust->trust_tier !== UserOcrTrust::TIER_TRUSTED) {
            return true;
        }

        if ($deviceOcrText === null || trim($deviceOcrText) === '') {
            return true;
        }

        return random_int(1, 100) <= self::TRUSTED_SAMPLE_RATE_PERCENT;
    }

    public function recordMatch(User $user): void
    {
        $trust = $this->trustFor($user);
        $trust->increment('consecutive_verified_count');

        $threshold = $trust->trust_tier === UserOcrTrust::TIER_PROBATION
            ? self::PROBATION_VERIFIED_THRESHOLD
            : self::NEW_ACCOUNT_VERIFIED_THRESHOLD;

        if ($trust->trust_tier !== UserOcrTrust::TIER_TRUSTED && $trust->fresh()->consecutive_verified_count >= $threshold) {
            $trust->update(['trust_tier' => UserOcrTrust::TIER_TRUSTED]);
        }
    }

    public function recordMismatch(User $user): void
    {
        $this->trustFor($user)->update([
            'trust_tier' => UserOcrTrust::TIER_PROBATION,
            'consecutive_verified_count' => 0,
            'last_mismatch_at' => now(),
        ]);
    }

    private function trustFor(User $user): UserOcrTrust
    {
        return UserOcrTrust::query()->firstOrCreate(['user_id' => $user->id]);
    }
}
