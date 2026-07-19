<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PostResource;
use App\Models\User;
use App\Services\FeedService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
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

        return PostResource::collection($posts);
    }
}
