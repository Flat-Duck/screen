<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IndexExploreRequest;
use App\Http\Resources\PostResource;
use App\Models\User;
use App\Services\FeedService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExploreController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function index(IndexExploreRequest $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $validated = $request->validated();

        $page = max(1, (int) $request->integer('page', 1));

        $posts = $this->feed->explore($viewer, $page, category: $validated['category'] ?? null, country: $validated['country'] ?? null);
        $this->likes->annotateLikes($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }
}
