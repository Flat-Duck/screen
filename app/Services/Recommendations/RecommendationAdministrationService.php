<?php

namespace App\Services\Recommendations;

use App\Models\Post;
use App\Models\RecommendationExclusion;
use App\Models\User;
use App\Services\AdminAuditLogger;
use App\Services\FeatureConfigurationService;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class RecommendationAdministrationService
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly FeatureConfigurationService $features,
    ) {}

    public function exclude(Post $post, User $actor, string $reason, ?CarbonInterface $expiresAt): RecommendationExclusion
    {
        if (mb_strlen(trim($reason)) < 3 || ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now()))) {
            throw ValidationException::withMessages(['reason' => ['A reason and future expiration are required when provided.']]);
        }
        $exclusion = RecommendationExclusion::create([
            'post_id' => $post->id, 'created_by' => $actor->id, 'reason' => trim($reason), 'expires_at' => $expiresAt,
        ]);
        $this->audit->record($actor, 'recommendation_exclusion.created', $exclusion, $reason, null, $exclusion->only(['post_id', 'expires_at']));

        return $exclusion;
    }

    public function restore(RecommendationExclusion $exclusion, User $actor, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }
        $before = $exclusion->only(['post_id', 'expires_at']);
        $this->audit->record($actor, 'recommendation_exclusion.removed', $exclusion, $reason, $before, null);
        $exclusion->delete();
    }

    public function setServing(User $actor, bool $enabled, string $reason): void
    {
        $this->features->configureFlag($actor, 'recommendations.serving', [
            'name' => 'Recommendation serving', 'scope' => 'recommendation', 'is_enabled' => $enabled,
            'kill_switch' => ! $enabled, 'rollout_basis_points' => 10000,
        ], $reason);
    }
}
