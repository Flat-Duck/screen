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

        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            // Format only, same reasoning as UpdateProfileRequest's own country_code rule —
            // not validated against the full ISO 3166-1 alpha-2 list.
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
        ]);

        $page = max(1, (int) $request->integer('page', 1));

        $posts = $this->feed->explore($viewer, $page, category: $validated['category'] ?? null, country: $validated['country'] ?? null);
        $this->likes->annotateIsLiked($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }
}
