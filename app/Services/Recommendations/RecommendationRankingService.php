<?php

namespace App\Services\Recommendations;

use App\Data\Recommendations\RankedRecommendation;
use App\Data\Recommendations\RecommendationCandidate;
use App\Enums\CandidateSource;
use App\Enums\ContentEventType;
use App\Models\ContentEvent;
use App\Models\DailyPostMetric;
use App\Models\Post;
use App\Models\RecommendationTargetFeedback;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserTopicAffinity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationRankingService
{
    /**
     * @param  Collection<int, RecommendationCandidate>  $candidates
     * @return Collection<int, RankedRecommendation>
     */
    public function rank(User $viewer, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return collect();
        }

        $postIds = $candidates->pluck('postId')->all();
        $posts = Post::query()->whereIn('id', $postIds)->with('user:id,created_at')->get()->keyBy('id');
        $authorAffinity = UserAuthorAffinity::query()->where('user_id', $viewer->id)
            ->whereIn('author_id', $posts->pluck('user_id'))->orderByDesc('affinity_date')->get()->unique('author_id')->keyBy('author_id');
        $topicAffinity = UserTopicAffinity::query()->where('user_id', $viewer->id)
            ->whereIn('category_id', $posts->pluck('category_id')->filter())->orderByDesc('affinity_date')->get()->unique('category_id')->keyBy('category_id');
        $metrics = DailyPostMetric::query()->whereIn('post_id', $postIds)
            ->selectRaw('post_id, SUM(unique_viewers) unique_viewers, SUM(impressions) impressions, SUM(opens) opens, SUM(saves) saves, SUM(reposts) reposts, SUM(shares) shares, SUM(hides) hides, SUM(not_interested) not_interested, SUM(reports) reports')
            ->groupBy('post_id')->get()->keyBy('post_id');
        $seen = ContentEvent::query()->where('user_id', $viewer->id)->whereIn('post_id', $postIds)
            ->where('event_type', ContentEventType::Impression)->selectRaw('post_id, COUNT(*) total')
            ->groupBy('post_id')->pluck('total', 'post_id');
        $followingIds = $viewer->following()->pluck('users.id');
        $socialProof = DB::table('follows')->whereIn('follower_id', $followingIds)
            ->whereIn('followee_id', $posts->pluck('user_id'))->selectRaw('followee_id, COUNT(*) total')
            ->groupBy('followee_id')->pluck('total', 'followee_id');
        $reducedAuthors = RecommendationTargetFeedback::query()->where('user_id', $viewer->id)
            ->where('target_type', RecommendationTargetFeedback::AUTHOR)->pluck('target_id');
        $reducedHashtags = RecommendationTargetFeedback::query()->where('user_id', $viewer->id)
            ->where('target_type', RecommendationTargetFeedback::HASHTAG)->pluck('target_id');
        $reducedTopicPosts = $reducedHashtags->isEmpty() ? collect() : DB::table('hashtag_post')
            ->whereIn('hashtag_id', $reducedHashtags)->whereIn('post_id', $postIds)->pluck('post_id');
        $meaningfulEvents = ContentEvent::query()->where('user_id', $viewer->id)
            ->whereIn('event_type', [
                ContentEventType::Open, ContentEventType::Like, ContentEventType::Comment,
                ContentEventType::Save, ContentEventType::Repost, ContentEventType::Share,
                ContentEventType::FollowAuthor, ContentEventType::Hide, ContentEventType::NotInterested,
                ContentEventType::Report,
            ])->count();
        $explicitInterestWeight = $meaningfulEvents <= 20 ? 20.0 : ($meaningfulEvents <= 100 ? 12.0 : 6.0);

        $ranked = $candidates->map(function (RecommendationCandidate $candidate) use ($posts, $authorAffinity, $topicAffinity, $metrics, $seen, $socialProof, $reducedAuthors, $reducedTopicPosts, $explicitInterestWeight): ?RankedRecommendation {
            $post = $posts->get($candidate->postId);
            if (! $post instanceof Post) {
                return null;
            }
            $metric = $metrics->get($post->id);
            $impressions = max(1, (int) ($metric->impressions ?? 0));
            $positive = (int) ($metric->opens ?? 0) + 3 * (int) ($metric->saves ?? 0)
                + 3 * (int) ($metric->reposts ?? 0) + 2 * (int) ($metric->shares ?? 0);
            $negative = (int) ($metric->hides ?? 0) + 2 * (int) ($metric->not_interested ?? 0)
                + 3 * (int) ($metric->reports ?? 0);
            $authorScore = $authorAffinity->has($post->user_id) ? (float) $authorAffinity->get($post->user_id)->score : 0.0;
            $topicScore = $post->category_id !== null && $topicAffinity->has($post->category_id)
                ? (float) $topicAffinity->get($post->category_id)->score : 0.0;
            $components = [
                'source' => min(15, max(0, $candidate->sourceScore * 15)),
                'author_affinity' => max(-20, min(20, $authorScore)),
                'topic_affinity' => max(-15, min(15, $topicScore)),
                'explicit_interest' => $candidate->source === CandidateSource::OnboardingInterest
                    || in_array(CandidateSource::OnboardingInterest->value, (array) ($candidate->eligibility['additional_sources'] ?? []), true)
                    ? $explicitInterestWeight : 0.0,
                'freshness' => 20 / (1 + max(0, $post->created_at->diffInHours(now())) / 24),
                'engagement_quality' => min(20, ($positive / $impressions) * 10),
                'social_proof' => min(10, (float) ($socialProof[$post->user_id] ?? 0) * 2),
                'new_creator' => $post->user->created_at?->gte(now()->subDays(90)) ? 4.0 : 0.0,
                'already_seen' => -min(20, (float) ($seen[$post->id] ?? 0) * 5),
                'safety_manipulation' => -min(20, ($negative / $impressions) * 20),
                'show_fewer' => ($reducedAuthors->contains($post->user_id) ? -12.0 : 0.0)
                    + ($reducedTopicPosts->contains($post->id) ? -10.0 : 0.0),
            ];

            return new RankedRecommendation(
                postId: $post->id,
                source: $candidate->source->value,
                score: round(array_sum($components), 6),
                reason: $this->reason($candidate->source),
                components: array_map(fn (float $score): float => round($score, 6), $components),
                eligibility: $candidate->eligibility,
            );
        })->filter()->values();

        return $this->diversify($ranked);
    }

    /**
     * @param  Collection<int, RankedRecommendation>  $ranked
     * @return Collection<int, RankedRecommendation>
     */
    private function diversify(Collection $ranked): Collection
    {
        $remaining = $ranked->sortByDesc('score')->values();
        $ordered = collect();
        $pageSize = (int) config('social.recommendations.page_size', 15);
        $maxAuthor = (int) config('social.recommendations.diversity.max_per_author', 2);
        $maxCategory = (int) config('social.recommendations.diversity.max_per_category', 4);
        $maxSource = (int) ceil($pageSize * (float) config('social.recommendations.diversity.max_source_share', 0.5));

        while ($remaining->isNotEmpty()) {
            $page = collect();
            $authorCounts = $categoryCounts = $sourceCounts = [];
            while ($page->count() < $pageSize) {
                if ($remaining->isEmpty()) {
                    break;
                }
                $index = $remaining->search(function (RankedRecommendation $item) use ($authorCounts, $categoryCounts, $sourceCounts, $maxAuthor, $maxCategory, $maxSource): bool {
                    $author = (int) $item->eligibility['author_id'];
                    $category = (int) ($item->eligibility['category_id'] ?? 0);

                    return ($authorCounts[$author] ?? 0) < $maxAuthor
                        && ($category === 0 || ($categoryCounts[$category] ?? 0) < $maxCategory)
                        && ($sourceCounts[$item->source] ?? 0) < $maxSource;
                });
                // Sparse pools should still be served; limits are relaxed only when no alternative exists.
                $index = $index === false ? $remaining->keys()->first() : $index;
                $item = $remaining->pull($index);
                if (! $item instanceof RankedRecommendation) {
                    continue;
                }
                $page->push($item);
                $author = (int) $item->eligibility['author_id'];
                $category = (int) ($item->eligibility['category_id'] ?? 0);
                $authorCounts[$author] = ($authorCounts[$author] ?? 0) + 1;
                $sourceCounts[$item->source] = ($sourceCounts[$item->source] ?? 0) + 1;
                if ($category !== 0) {
                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                }
            }
            $ordered->push(...$page);
        }

        return $ordered->values();
    }

    private function reason(CandidateSource $source): string
    {
        return match ($source) {
            CandidateSource::Following => 'From an account you follow',
            CandidateSource::FollowedHashtag => 'Related to a hashtag you follow',
            CandidateSource::OnboardingInterest => 'Because you selected this interest',
            CandidateSource::Category, CandidateSource::SimilarTopic => 'Related to topics you engage with',
            CandidateSource::Trending => 'Popular screenshots right now',
            CandidateSource::RegionalTrending => 'Popular screenshots in your region',
            CandidateSource::TwoHop => 'Followed by people in your network',
            CandidateSource::SimilarAuthor => 'From a creator you may like',
            CandidateSource::NewCreator => 'Discover a new creator',
            default => 'Recommended for you',
        };
    }
}
