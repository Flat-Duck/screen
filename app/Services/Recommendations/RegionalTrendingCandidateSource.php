<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

class RegionalTrendingCandidateSource extends TrendingCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::RegionalTrending;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        if ($viewer->country_code === null) {
            return collect();
        }

        $country = strtolower($viewer->country_code);
        $ids = $this->hotIds((string) config('social.recommendations.hot_pool_prefix').':country:'.$country, $limit * 3);
        $query = $this->eligibility->query($viewer, publicOnly: true)
            ->whereHas('user', fn ($users) => $users->where('country_code', $viewer->country_code))
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.trending_days', 7)));

        if ($ids === []) {
            $posts = $query->withCount(['likes', 'comments'])->orderByDesc('likes_count')->latest('id')->limit($limit)->get();
        } else {
            $posts = $query->whereIn('id', $ids)->get()->sortBy(fn (Post $post): int => $this->rank($ids, $post->id))->take($limit)->values();
        }

        return $this->candidates($posts, $this->source(), fn ($post, $index): float => 1 / ($index + 1), fn (): array => ['country_code' => strtoupper($country)]);
    }
}
