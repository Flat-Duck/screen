<?php

namespace App\Services;

use App\Models\CollectionItem;
use App\Models\Post;
use App\Models\SavedCollection;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SavedCollectionService
{
    public function __construct(
        private readonly SavedPostService $savedPosts,
        private readonly BlockService $blocks,
    ) {}

    /** @return Collection<int, SavedCollection> */
    public function collections(User $user): Collection
    {
        return SavedCollection::query()->where('user_id', $user->id)->withCount('items')->orderBy('position')->get();
    }

    /** @param array{name: string, description?: string|null} $data */
    public function create(User $user, array $data): SavedCollection
    {
        return DB::transaction(function () use ($user, $data): SavedCollection {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $maxPosition = SavedCollection::query()->where('user_id', $user->id)->max('position');
            $position = $maxPosition === null ? 0 : (int) $maxPosition + 1;

            return SavedCollection::create([
                'user_id' => $user->id, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'position' => $position, 'visibility' => 'private', 'version' => 1,
            ])->loadCount('items');
        });
    }

    /** @param array{name?: string, description?: string|null, position?: int, version: int} $data */
    public function update(User $user, SavedCollection $collection, array $data): SavedCollection
    {
        return DB::transaction(function () use ($user, $collection, $data): SavedCollection {
            $locked = $this->lockOwned($user, $collection->id);
            $this->assertVersion($locked->version, $data['version']);
            if (array_key_exists('position', $data)) {
                $this->moveCollection($locked, $data['position']);
            }
            $locked->fill(array_filter([
                'name' => $data['name'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
            ], fn ($value, $key): bool => array_key_exists($key, $data), ARRAY_FILTER_USE_BOTH));
            $locked->version++;
            $locked->save();

            return $locked->loadCount('items');
        });
    }

    public function delete(User $user, SavedCollection $collection, int $version): void
    {
        DB::transaction(function () use ($user, $collection, $version): void {
            $locked = $this->lockOwned($user, $collection->id);
            $this->assertVersion($locked->version, $version);
            $oldPosition = $locked->position;
            $locked->delete();
            SavedCollection::query()->where('user_id', $user->id)->where('position', '>', $oldPosition)
                ->update(['position' => DB::raw('position - 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        });
    }

    /** @return CursorPaginator<int, CollectionItem> */
    public function items(User $user, SavedCollection $collection, int $perPage = 20): CursorPaginator
    {
        $owned = $this->owned($user, $collection->id);
        $visiblePosts = Post::query()->visibleTo($user)->select('id');
        $visiblePosts = $this->blocks->excludeBlocked($visiblePosts, $user, 'user_id');

        $items = CollectionItem::query()->where('collection_id', $owned->id)->whereIn('post_id', $visiblePosts)
            ->orderBy('position')->orderBy('id')->cursorPaginate($perPage);
        $posts = Post::query()->whereIn('id', $items->getCollection()->pluck('post_id'))
            ->with(['user', 'media', 'category'])->withCount(['likes', 'comments'])->get()->keyBy('id');
        $items->getCollection()->each(fn (CollectionItem $item) => $item->setRelation('post', $posts->get($item->post_id)));

        return $items;
    }

    public function addItem(User $user, SavedCollection $collection, Post $post, int $collectionVersion, ?string $note, ?int $position): CollectionItem
    {
        return DB::transaction(function () use ($user, $collection, $post, $collectionVersion, $note, $position): CollectionItem {
            $locked = $this->lockOwned($user, $collection->id);
            $existing = CollectionItem::query()->where('collection_id', $locked->id)->where('post_id', $post->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('post');
            }
            $this->assertVersion($locked->version, $collectionVersion);
            abort_unless($post->isVisibleTo($user) && ! $this->blocks->isBlockedEitherWay($user, $post->user), 404);
            $this->savedPosts->save($user, $post);
            $count = CollectionItem::query()->where('collection_id', $locked->id)->count();
            $target = min(max(0, $position ?? $count), $count);
            CollectionItem::query()->where('collection_id', $locked->id)->where('position', '>=', $target)
                ->update(['position' => DB::raw('position + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $item = CollectionItem::create([
                'collection_id' => $locked->id, 'post_id' => $post->id, 'note' => $note,
                'position' => $target, 'version' => 1,
            ]);
            $this->touchCollectionVersion($locked);

            return $item->load('post');
        });
    }

    /** @param array{collection_version: int, version: int, note?: string|null, position?: int} $data */
    public function updateItem(User $user, SavedCollection $collection, Post $post, array $data): CollectionItem
    {
        return DB::transaction(function () use ($user, $collection, $post, $data): CollectionItem {
            $locked = $this->lockOwned($user, $collection->id);
            $this->assertVersion($locked->version, $data['collection_version']);
            $item = CollectionItem::query()->where('collection_id', $locked->id)->where('post_id', $post->id)->lockForUpdate()->firstOrFail();
            abort_unless($post->isVisibleTo($user) && ! $this->blocks->isBlockedEitherWay($user, $post->user), 404);
            $this->assertVersion($item->version, $data['version']);
            if (array_key_exists('position', $data)) {
                $this->moveItem($item, $data['position']);
            }
            if (array_key_exists('note', $data)) {
                $item->note = $data['note'];
            }
            $item->version++;
            $item->save();
            $this->touchCollectionVersion($locked);

            return $item->load('post');
        });
    }

    public function removeItem(User $user, SavedCollection $collection, Post $post, int $collectionVersion, int $itemVersion): void
    {
        DB::transaction(function () use ($user, $collection, $post, $collectionVersion, $itemVersion): void {
            $locked = $this->lockOwned($user, $collection->id);
            $item = CollectionItem::query()->where('collection_id', $locked->id)->where('post_id', $post->id)->lockForUpdate()->first();
            if (! $item) {
                return;
            }
            $this->assertVersion($locked->version, $collectionVersion);
            $this->assertVersion($item->version, $itemVersion);
            $oldPosition = $item->position;
            $item->delete();
            CollectionItem::query()->where('collection_id', $locked->id)->where('position', '>', $oldPosition)
                ->update(['position' => DB::raw('position - 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->touchCollectionVersion($locked);
        });
    }

    private function owned(User $user, int $id): SavedCollection
    {
        return SavedCollection::query()->whereKey($id)->where('user_id', $user->id)->firstOrFail();
    }

    private function lockOwned(User $user, int $id): SavedCollection
    {
        return SavedCollection::query()->whereKey($id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new ConflictHttpException('The collection changed on another device. Refresh and retry.');
        }
    }

    private function moveCollection(SavedCollection $collection, int $requested): void
    {
        $max = max(0, SavedCollection::query()->where('user_id', $collection->user_id)->count() - 1);
        $target = min(max(0, $requested), $max);
        if ($target === $collection->position) {
            return;
        }
        $query = SavedCollection::query()->where('user_id', $collection->user_id)->whereKeyNot($collection->id);
        if ($target < $collection->position) {
            $query->whereBetween('position', [$target, $collection->position - 1])->update(['position' => DB::raw('position + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        } else {
            $query->whereBetween('position', [$collection->position + 1, $target])->update(['position' => DB::raw('position - 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
        $collection->position = $target;
    }

    private function moveItem(CollectionItem $item, int $requested): void
    {
        $max = max(0, CollectionItem::query()->where('collection_id', $item->collection_id)->count() - 1);
        $target = min(max(0, $requested), $max);
        if ($target === $item->position) {
            return;
        }
        $query = CollectionItem::query()->where('collection_id', $item->collection_id)->whereKeyNot($item->id);
        if ($target < $item->position) {
            $query->whereBetween('position', [$target, $item->position - 1])->update(['position' => DB::raw('position + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        } else {
            $query->whereBetween('position', [$item->position + 1, $target])->update(['position' => DB::raw('position - 1'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
        $item->position = $target;
    }

    private function touchCollectionVersion(SavedCollection $collection): void
    {
        $collection->version++;
        $collection->save();
    }
}
