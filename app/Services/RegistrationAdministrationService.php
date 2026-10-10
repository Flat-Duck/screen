<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Thin wrapper over FeatureConfigurationService for the registration.invite_only flag —
 * same shape as RecommendationAdministrationService::setServing(). */
class RegistrationAdministrationService
{
    public function __construct(private readonly FeatureConfigurationService $features) {}

    /** @param array<string, array{enabled: bool, points: int}> $rewards */
    public function setInviteOnly(User $actor, bool $enabled, int $pointsPerInvite, int $maturityDays, string $reason, array $rewards = []): void
    {
        // Keep old clients and bookmarked admin forms compatible during rollout.
        if ($rewards === []) {
            $rewards = [
                PointRewardService::INVITEE_REGISTRATION => ['enabled' => true, 'points' => $pointsPerInvite],
                PointRewardService::INVITER_REFERRAL => ['enabled' => true, 'points' => $pointsPerInvite],
            ];
        }

        DB::transaction(function () use ($actor, $enabled, $pointsPerInvite, $maturityDays, $reason, $rewards): void {
            $this->features->configureFlag($actor, 'registration.invite_only', [
                'name' => 'Invite-only registration',
                'scope' => 'product',
                'is_enabled' => true,
                'kill_switch' => ! $enabled,
                'rollout_basis_points' => 10000,
                'payload' => ['points_per_invite' => $pointsPerInvite, 'maturity_days' => $maturityDays],
            ], $reason);

            $catalog = PointRewardService::catalog();
            foreach ($rewards as $action => $settings) {
                if (! isset($catalog[$action])) {
                    continue;
                }

                $this->features->configureFlag($actor, 'points.reward.'.$action, [
                    'name' => $catalog[$action]['label'],
                    'scope' => 'product',
                    'is_enabled' => (bool) $settings['enabled'],
                    'kill_switch' => ! (bool) $settings['enabled'],
                    'rollout_basis_points' => 10000,
                    'payload' => ['points' => (int) $settings['points']],
                ], $reason);
            }
        });
    }
}
