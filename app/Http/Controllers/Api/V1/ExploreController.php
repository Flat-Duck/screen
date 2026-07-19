<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PostResource;
use App\Models\User;
use App\Services\FeedService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExploreController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $page = max(1, (int) $request->integer('page', 1));

        $posts = $this->feed->explore($viewer, $page);
        $this->likes->annotateIsLiked($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }
}
