<?php

namespace App\Services\Recommendations;

use App\Enums\AccountVisibility;
use App\Enums\ContentEventType;
use App\Enums\UserRestrictionType;
use App\Models\ContentEvent;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\RecommendationExclusion;
use App\Models\RecommendationPostFeedback;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\BlockService;
use App\Services\MuteService;
use Illuminate\Database\Eloquent\Builder;

class CandidateEligibilityService
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly MuteService $mutes,
    ) {}

    /** @return Builder<Post> */
    public function query(User $viewer, bool $publicOnly = false): Builder
    {
        $query = Post::query()
            ->visibleTo($viewer)
            ->where('recommendation_eligible', true)
            ->where('user_id', '!=', $viewer->id)
            ->whereNotIn('user_id', UserRestriction::query()->active()
                ->where('type', UserRestrictionType::Recommendation)->select('user_id'))
            ->whereDoesntHave('media', fn (Builder $media): Builder => $media->whereIn('safety_status', [
                PostMedia::SAFETY_WARNING,
                PostMedia::PROCESSING_FAILED,
            ]))
            ->whereNotIn('id', ContentEvent::query()->where('user_id', $viewer->id)
                ->whereIn('event_type', [ContentEventType::Hide, ContentEventType::NotInterested, ContentEventType::Report])
                ->whereNotNull('post_id')->select('post_id'));

        $query->whereNotIn('id', RecommendationPostFeedback::query()->where('user_id', $viewer->id)->select('post_id'))
            ->whereNotIn('id', RecommendationExclusion::query()->active()->select('post_id'));

        if ($publicOnly) {
            $query->whereIn('user_id', User::query()->publiclyVisible()
                ->where('account_visibility', AccountVisibility::Public->value)->select('id'));
        }

        $query = $this->blocks->excludeBlocked($query, $viewer, 'user_id');

        return $this->mutes->excludeMuted($query, $viewer, 'user_id');
    }
}
