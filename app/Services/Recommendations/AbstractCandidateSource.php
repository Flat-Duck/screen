<?php

namespace App\Services\Recommendations;

use App\Contracts\RecommendationCandidateSource;
use App\Data\Recommendations\RecommendationCandidate;
use App\Enums\CandidateSource;
use App\Models\Post;
use Illuminate\Support\Collection;

abstract class AbstractCandidateSource implements RecommendationCandidateSource
{
    public function __construct(protected readonly CandidateEligibilityService $eligibility) {}

    /**
     * @param  Collection<int, Post>  $posts
     * @param  callable(Post, int): float  $score
     * @param  callable(Post): array<string, mixed>|null  $metadata
     * @return Collection<int, RecommendationCandidate>
     */
    protected function candidates(Collection $posts, CandidateSource $source, callable $score, ?callable $metadata = null): Collection
    {
        $generatedAt = now();

        return $posts->values()->map(fn (Post $post, int $index): RecommendationCandidate => new RecommendationCandidate(
            postId: $post->id,
            source: $source,
            sourceScore: round($score($post, $index), 6),
            generatedAt: $generatedAt,
            eligibility: [
                'author_id' => $post->user_id,
                'category_id' => $post->category_id,
                'public_discovery' => $source !== CandidateSource::Following,
                ...($metadata ? $metadata($post) : []),
            ],
        ));
    }

    protected function freshness(Post $post): float
    {
        return 1 / (1 + max(0, $post->created_at->diffInHours(now())) / 24);
    }
}
