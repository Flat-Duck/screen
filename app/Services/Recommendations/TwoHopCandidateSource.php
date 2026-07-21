<?php

namespace App\Services\Recommendations;

use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TwoHopCandidateSource extends AbstractCandidateSource
{
    public function source(): CandidateSource
    {
        return CandidateSource::TwoHop;
    }

    public function generate(User $viewer, int $limit): Collection
    {
        $directIds = $viewer->following()->select('users.id');
        $twoHopIds = DB::table('follows as second_hop')->select('second_hop.followee_id')
            ->whereIn('second_hop.follower_id', $viewer->following()->select('users.id'))
            ->whereNotIn('second_hop.followee_id', $directIds)
            ->where('second_hop.followee_id', '!=', $viewer->id)
            ->groupBy('second_hop.followee_id')
            ->orderByRaw('count(*) desc')
            ->limit((int) config('social.recommendations.two_hop_author_limit', 100));

        $posts = $this->eligibility->query($viewer, publicOnly: true)->whereIn('user_id', $twoHopIds)
            ->where('created_at', '>=', now()->subDays((int) config('social.recommendations.windows.two_hop_days', 14)))
            ->latest('id')->limit($limit)->get();

        return $this->candidates($posts, $this->source(), fn ($post): float => $this->freshness($post));
    }
}
