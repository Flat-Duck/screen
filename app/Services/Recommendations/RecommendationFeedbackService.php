<?php

namespace App\Services\Recommendations;

use App\Models\ContentEvent;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\RecommendationFeedSession;
use App\Models\RecommendationPostFeedback;
use App\Models\RecommendationTargetFeedback;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserTopicAffinity;
use Illuminate\Support\Facades\DB;

class RecommendationFeedbackService
{
    public function notInterested(User $user, Post $post): void
    {
        $this->requireVisible($user, $post);
        $this->postFeedback($user, $post, RecommendationPostFeedback::NOT_INTERESTED);
    }

    public function restoreInterest(User $user, Post $post): void
    {
        RecommendationPostFeedback::query()->where('user_id', $user->id)->where('post_id', $post->id)
            ->where('type', RecommendationPostFeedback::NOT_INTERESTED)->delete();
        RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
    }

    public function hide(User $user, Post $post): void
    {
        $this->requireVisible($user, $post);
        $this->postFeedback($user, $post, RecommendationPostFeedback::HIDDEN);
    }

    public function showFewerFromAuthor(User $user, User $author): void
    {
        abort_if($user->is($author), 422, 'You cannot reduce your own recommendations.');
        RecommendationTargetFeedback::query()->firstOrCreate([
            'user_id' => $user->id, 'target_type' => RecommendationTargetFeedback::AUTHOR, 'target_id' => $author->id,
        ]);
        RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
    }

    public function showFewerFromHashtag(User $user, Hashtag $hashtag): void
    {
        RecommendationTargetFeedback::query()->firstOrCreate([
            'user_id' => $user->id, 'target_type' => RecommendationTargetFeedback::HASHTAG, 'target_id' => $hashtag->id,
        ]);
        RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
    }

    public function resetProfile(User $user, bool $clearExplicitInterests = false): void
    {
        DB::transaction(function () use ($user, $clearExplicitInterests): void {
            RecommendationPostFeedback::query()->where('user_id', $user->id)->delete();
            RecommendationTargetFeedback::query()->where('user_id', $user->id)->delete();
            UserAuthorAffinity::query()->where('user_id', $user->id)->delete();
            UserTopicAffinity::query()->where('user_id', $user->id)->delete();
            ContentEvent::query()->where('user_id', $user->id)->delete();
            RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
            if ($clearExplicitInterests) {
                $user->interests()->detach();
                $user->forceFill(['interests_completed_at' => null, 'interests_skipped_at' => null])->saveQuietly();
            }
        });
    }

    private function postFeedback(User $user, Post $post, string $type): void
    {
        RecommendationPostFeedback::query()->firstOrCreate(['user_id' => $user->id, 'post_id' => $post->id, 'type' => $type]);
        RecommendationFeedSession::query()->where('user_id', $user->id)->delete();
    }

    private function requireVisible(User $user, Post $post): void
    {
        abort_unless($post->isVisibleTo($user), 404);
    }
}
