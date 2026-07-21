<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use Illuminate\Support\Collection;

class SimilarAuthorCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::SimilarAuthor;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $authorIds = UserAuthorAffinity::query()->where('user_id', $viewer->id)->where('score', '>', 0)
            ->orderByDesc('affinity_date')->orderByDesc('score')->limit(20)->pluck('author_id')->unique();
        if ($authorIds->isEmpty()) {
            return collect();
        }

        $posts = $this->eligibility->query($viewer, publicOnly: true)->whereIn('user_id', $authorIds)
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.similar_author_days', 30)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post), fn (): array => ['positive_author_affinity' => true]);
    }
}
