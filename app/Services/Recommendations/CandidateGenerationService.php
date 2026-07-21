<?php

namespace App\Services\Recommendations;

use App\Contracts\RecommendationCandidateSource;
use App\Data\Recommendations\RecommendationCandidate;
use App\Models\User;
use Illuminate\Support\Collection;

class CandidateGenerationService
{
    /** @var list<RecommendationCandidateSource> */
    private array $sources;

    public function __construct(
        FollowingCandidateSource $following,
        OnboardingInterestCandidateSource $onboardingInterests,
        InterestCandidateSource $hashtags,
        CategoryCandidateSource $categories,
        TrendingCandidateSource $trending,
        RegionalTrendingCandidateSource $regionalTrending,
        TwoHopCandidateSource $twoHop,
        SimilarAuthorCandidateSource $similarAuthor,
        SimilarTopicCandidateSource $similarTopic,
        NewCreatorCandidateSource $newCreator,
    ) {
        $this->sources = [$following, $onboardingInterests, $hashtags, $categories, $trending, $regionalTrending, $twoHop, $similarAuthor, $similarTopic, $newCreator];
    }

    /** @return Collection<int, RecommendationCandidate> */
    public function generate(User $viewer): Collection
    {
        /** @var array<int, RecommendationCandidate> $deduplicated */
        $deduplicated = [];

        foreach ($this->sources as $source) {
            $limit = (int) config('social.recommendations.source_limits.'.$source->source()->value, 25);
            if ($limit <= 0) {
                continue;
            }

            foreach ($source->generate($viewer, $limit) as $candidate) {
                if (! isset($deduplicated[$candidate->postId])) {
                    $deduplicated[$candidate->postId] = $candidate;

                    continue;
                }

                $existing = $deduplicated[$candidate->postId];
                $additional = (array) ($existing->eligibility['additional_sources'] ?? []);
                $additional[] = $candidate->source->value;
                $deduplicated[$candidate->postId] = new RecommendationCandidate(
                    postId: $existing->postId,
                    source: $existing->source,
                    sourceScore: $existing->sourceScore,
                    generatedAt: $existing->generatedAt,
                    eligibility: [...$existing->eligibility, 'additional_sources' => array_values(array_unique($additional))],
                );
            }
        }

        return collect(array_values($deduplicated))
            ->take((int) config('social.recommendations.total_limit', 250))
            ->values();
    }
}
