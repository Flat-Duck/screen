<?php

namespace App\Http\Controllers\Api\V1;

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

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'mine' => ['nullable', 'boolean'],
        ]);

        return GroupResource::collection(
            $this->groups->discover($this->user($request), $validated['q'] ?? null, (bool) ($validated['mine'] ?? false)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', 'string', 'in:public,private'],
            'is_discoverable' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=100,min_height=100'],
        ]);

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
