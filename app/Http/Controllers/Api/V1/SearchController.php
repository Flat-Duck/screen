<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\HashtagResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\HashtagService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use App\Services\SearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
        private readonly HashtagService $hashtagFollows,
    ) {}

    public function users(SearchRequest $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $users = $this->search->users($request->validated()['q'], $viewer);

        return UserSummaryResource::collection($users);
    }

    public function posts(SearchRequest $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $posts = $this->search->posts($request->validated()['q'], $viewer);
        $postItems = collect($posts->items());
        $this->likes->annotateIsLiked($postItems, $viewer);
        $this->savedPosts->annotateIsSaved($postItems, $viewer);

        return PostResource::collection($posts);
    }

    public function hashtags(SearchRequest $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $hashtags = $this->search->hashtags($request->validated()['q']);
        $this->hashtagFollows->annotateIsFollowed(collect($hashtags->items()), $viewer);

        return HashtagResource::collection($hashtags);
    }
}
