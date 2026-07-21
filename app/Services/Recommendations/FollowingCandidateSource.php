<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;

class FollowingCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::Following;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $posts = $this->eligibility->query($viewer)
            ->whereIn('user_id', $viewer->following()->select('users.id'))
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.following_days', 14)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post));
    }
}
