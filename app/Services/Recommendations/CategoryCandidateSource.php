<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use App\Models\UserTopicAffinity;
use Illuminate\Support\Collection;

class CategoryCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::Category;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $categoryIds = UserTopicAffinity::query()->where('user_id', $viewer->id)->where('score', '>', 0)
            ->orderByDesc('affinity_date')->orderByDesc('score')->limit(10)->pluck('category_id');
        if ($categoryIds->isEmpty()) {
            return collect();
        }

        $posts = $this->eligibility->query($viewer, publicOnly: true)->whereIn('category_id', $categoryIds)
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.interest_days', 30)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post), fn (): array => ['matched_category' => true]);
    }
}
