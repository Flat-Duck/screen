<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;

class InterestCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::FollowedHashtag;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $hashtagIds = $viewer->followedHashtags()->pluck('hashtags.id');
        if ($hashtagIds->isEmpty()) {
            return collect();
        }

        $posts = $this->eligibility->query($viewer, publicOnly: true)
            ->whereHas('hashtags', fn ($hashtags) => $hashtags->whereIn('hashtags.id', $hashtagIds))
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.interest_days', 30)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post), fn (): array => ['matched_hashtag' => true]);
    }
}
