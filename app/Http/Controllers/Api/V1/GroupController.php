<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IndexGroupRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Resources\GroupResource;
use App\Http\Resources\PostResource;
use App\Models\Group;
use App\Models\Post;
use App\Models\User;
use App\Services\GroupService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function index(IndexGroupRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        return GroupResource::collection(
            $this->groups->discover($this->user($request), $validated['q'] ?? null, (bool) ($validated['mine'] ?? false)),
        );
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $data = $request->validated();

        $group = $this->groups->create($this->user($request), $data);

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function show(Request $request, Group $group): GroupResource
    {
        return new GroupResource($this->groups->show($this->user($request), $group));
    }

    public function join(Request $request, Group $group): JsonResponse
    {
        $this->groups->join($this->user($request), $group);

        return response()->json(null, 204);
    }

    public function leave(Request $request, Group $group): JsonResponse
    {
        $this->groups->leave($this->user($request), $group);

        return response()->json(null, 204);
    }

    public function posts(Request $request, Group $group): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $posts = $this->groups->posts($user, $group);
        $this->likes->annotateLikes($posts->getCollection(), $user);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $user);

        return PostResource::collection($posts);
    }

    public function share(Request $request, Group $group, Post $post): JsonResponse
    {
        $this->groups->shareIntoGroup($this->user($request), $group, $post);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
