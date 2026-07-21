<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CollectionItemResource;
use App\Http\Resources\SavedCollectionResource;
use App\Models\CollectionItem;
use App\Models\Post;
use App\Models\SavedCollection;
use App\Models\User;
use App\Services\LikeService;
use App\Services\SavedCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SavedCollectionController extends Controller
{
    public function __construct(
        private readonly SavedCollectionService $collections,
        private readonly LikeService $likes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SavedCollectionResource::collection($this->collections->collections($this->user($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', Rule::in(['private'])],
        ]);
        $collection = $this->collections->create($this->user($request), $data);

        return (new SavedCollectionResource($collection))->response()->setStatusCode(201);
    }

    public function update(Request $request, SavedCollection $collection): SavedCollectionResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'version' => ['required', 'integer', 'min:1'],
            'visibility' => ['sometimes', Rule::in(['private'])],
        ]);

        return new SavedCollectionResource($this->collections->update($this->user($request), $collection, $data));
    }

    public function destroy(Request $request, SavedCollection $collection): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->collections->delete($this->user($request), $collection, $data['version']);

        return response()->json(null, 204);
    }

    public function items(Request $request, SavedCollection $collection): AnonymousResourceCollection
    {
        $items = $this->collections->items($this->user($request), $collection);
        $posts = $items->getCollection()->pluck('post')->filter();
        $this->likes->annotateIsLiked($posts, $this->user($request));
        $posts->each(fn (Post $post) => $post->is_saved = true);

        return CollectionItemResource::collection($items)->additional([
            'collection' => new SavedCollectionResource($collection->fresh()->loadCount('items')),
        ]);
    }

    public function addItem(Request $request, SavedCollection $collection, Post $post): JsonResponse
    {
        $data = $request->validate([
            'collection_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $item = $this->collections->addItem($this->user($request), $collection, $post, $data['collection_version'], $data['note'] ?? null, $data['position'] ?? null);
        $this->hydrateItem($item, $this->user($request));

        return (new CollectionItemResource($item))->additional([
            'collection' => new SavedCollectionResource($collection->fresh()->loadCount('items')),
        ])->response()->setStatusCode($item->wasRecentlyCreated ? 201 : 200);
    }

    public function updateItem(Request $request, SavedCollection $collection, Post $post): CollectionItemResource
    {
        $data = $request->validate([
            'collection_version' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
        $item = $this->collections->updateItem($this->user($request), $collection, $post, $data);
        $this->hydrateItem($item, $this->user($request));

        return (new CollectionItemResource($item))->additional([
            'collection' => new SavedCollectionResource($collection->fresh()->loadCount('items')),
        ]);
    }

    public function removeItem(Request $request, SavedCollection $collection, Post $post): JsonResponse
    {
        $data = $request->validate([
            'collection_version' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $this->collections->removeItem($this->user($request), $collection, $post, $data['collection_version'], $data['version']);

        return response()->json(null, 204);
    }

    private function hydrateItem(CollectionItem $item, User $user): void
    {
        $post = $item->post()->with(['user', 'media', 'category'])->withCount(['likes', 'comments'])->firstOrFail();
        $post->is_saved = true;
        $this->likes->annotateIsLiked(collect([$post]), $user);
        $item->setRelation('post', $post);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
