<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class TrendingCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::Trending;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $ids = $this->hotIds((string) config('social.recommendations.hot_pool_prefix').':global', $limit * 3);
        $query = $this->eligibility->query($viewer, publicOnly: true)
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.trending_days', 7)));

        if ($ids === []) {
            $posts = $query->withCount(['likes', 'comments'])->orderByDesc('likes_count')->orderByDesc('comments_count')
                ->latest('id')->limit($limit)->get();
        } else {
            $posts = $query->whereIn('id', $ids)->get()->sortBy(fn (Post $post): int => $this->rank($ids, $post->id))->take($limit)->values();
        }

        return $this->candidates($posts, $this->source(), fn ($post, $index): float => 1 / ($index + 1));
    }

    /** @return list<string> */
    protected function hotIds(string $key, int $limit): array
    {
        try {
            return array_values(Redis::zrevrange($key, 0, max(0, $limit - 1)));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /** @param list<string> $ids */
    protected function rank(array $ids, int $postId): int
    {
        $position = array_search((string) $postId, $ids, true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}
