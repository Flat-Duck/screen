<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OnboardingInterestCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::OnboardingInterest;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $interestIds = $viewer->interests()->active()->pluck('interests.id');
        if ($interestIds->isEmpty()) {
            return collect();
        }

        $categoryIds = DB::table('interest_category')->whereIn('interest_id', $interestIds)->pluck('category_id');
        $hashtagIds = DB::table('hashtag_interest')->whereIn('interest_id', $interestIds)->pluck('hashtag_id');
        if ($categoryIds->isEmpty() && $hashtagIds->isEmpty()) {
            return collect();
        }

        $posts = $this->eligibility->query($viewer, publicOnly: true)
            ->where(function ($query) use ($categoryIds, $hashtagIds): void {
                if ($categoryIds->isNotEmpty()) {
                    $query->whereIn('category_id', $categoryIds);
                }
                if ($hashtagIds->isNotEmpty()) {
                    $method = $categoryIds->isNotEmpty() ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('hashtags', fn ($hashtags) => $hashtags->whereIn('hashtags.id', $hashtagIds));
                }
            })
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.interest_days', 30)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates(
            $posts,
            $this->source(),
            fn ($post): float => $this->freshness($post),
            fn (): array => ['explicit_interest' => true],
        );
    }
}
