<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PostResource;
use App\Models\User;
use App\Services\FeatureEvaluationService;
use App\Services\FeedService;
use App\Services\FollowService;
use App\Services\LikeService;
use App\Services\Recommendations\RecommendationFeedService;
use App\Services\SavedPostService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly FeatureEvaluationService $features,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
        private readonly RecommendationFeedService $recommendations,
        private readonly FollowService $follows,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $posts = $this->feed->feedFor($user);

        // Only the first page — a cursor points at a specific position in the pure
        // in-network sequence, so splicing extra posts into a later page would make it
        // meaningless.
        if (! $request->filled('cursor')) {
            $this->feed->injectDiscovery($posts, $user);
        }

        $this->likes->annotateIsLiked($posts->getCollection(), $user);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $user);
        $this->follows->annotatePostAuthorsAreFollowed($posts->getCollection(), $user);

        return PostResource::collection($posts)->additional([
            // Cast so an empty assignment set serialises as `{}` and not `[]`. PHP encodes an
            // empty associative array as a JSON array, and the Android client models this field
            // as Map<String, String>, so `[]` fails the whole response with
            // "Expected BEGIN_OBJECT but was BEGIN_ARRAY" — taking the entire feed page down, not
            // just this field. Every user with no active experiment hits that path.
            'experiment_assignments' => (object) $this->features->assignmentsFor($user),
        ]);
    }

    public function following(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $posts = $this->feed->feedFor($user);
        $this->likes->annotateIsLiked($posts->getCollection(), $user);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $user);
        $this->follows->annotatePostAuthorsAreFollowed($posts->getCollection(), $user);

        return PostResource::collection($posts)->additional([
            'experiment_assignments' => (object) $this->features->assignmentsFor($user),
        ]);
    }

    public function forYou(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $page = $this->recommendations->page($user, $validated['cursor'] ?? null, (int) ($validated['per_page'] ?? 15));
        $this->likes->annotateIsLiked($page->posts, $user);
        $this->savedPosts->annotateIsSaved($page->posts, $user);
        $this->follows->annotatePostAuthorsAreFollowed($page->posts, $user);

        return PostResource::collection($page->posts)->additional([
            'meta' => [
                'feed_session_id' => $page->sessionId,
                'request_id' => $page->requestId,
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->nextCursor !== null,
            ],
            'experiment_assignments' => (object) $this->features->assignmentsFor($user),
        ]);
    }
}
