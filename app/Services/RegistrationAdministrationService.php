<?php

namespace App\Services;

use App\Models\User;

/** Thin wrapper over FeatureConfigurationService for the registration.invite_only flag —
 * same shape as RecommendationAdministrationService::setServing(). */
class RegistrationAdministrationService
{
    public function __construct(private readonly FeatureConfigurationService $features) {}

    public function setInviteOnly(User $actor, bool $enabled, int $pointsPerInvite, int $maturityDays, string $reason): void
    {
        $this->features->configureFlag($actor, 'registration.invite_only', [
            'name' => 'Invite-only registration',
            'scope' => 'product',
            'is_enabled' => true,
            'kill_switch' => ! $enabled,
            'rollout_basis_points' => 10000,
            'payload' => ['points_per_invite' => $pointsPerInvite, 'maturity_days' => $maturityDays],
        ], $reason);
    }
}
