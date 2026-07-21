<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;

class NewCreatorCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::NewCreator;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $creatorDays = (int) config('social.recommendations.windows.new_creator_days', 90);
        $maxFollowers = (int) config('social.recommendations.new_creator_max_followers', 100);
        $posts = $this->eligibility->query($viewer, publicOnly: true)
            ->whereHas('user', fn ($users) => $users->where('created_at', '>=', now()->subDays($creatorDays))
                ->has('followers', '<=', $maxFollowers))
            ->where('created_at', '>=', now()->subDays($creatorDays))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post), fn (): array => ['new_creator' => true]);
    }
}
