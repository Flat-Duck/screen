<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupService
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly ImageProcessingService $images,
    ) {}

    /** @return CursorPaginator<int, Group> */
    public function discover(User $viewer, ?string $query, bool $mine, int $perPage = 20): CursorPaginator
    {
        $viewerGroupIds = GroupMember::query()->where('user_id', $viewer->id)->select('group_id');

        $groups = Group::query()
            ->when($query, fn (Builder $q) => $q->where('name', 'like', '%'.$query.'%'))
            ->when($mine, fn (Builder $q) => $q->whereIn('id', $viewerGroupIds))
            // A private group is invisible to browse/search unless the viewer is already a
            // member — `mine` already scopes to the viewer's own groups above, so this only
            // matters for the general (non-`mine`) listing.
            ->unless($mine, fn (Builder $q) => $q->where(
                fn (Builder $q) => $q->where('visibility', '!=', 'private')->orWhereIn('id', $viewerGroupIds)
            ))
            ->orderByDesc('member_count')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        $this->annotateViewer($groups->getCollection(), $viewer);

        return $groups;
    }

    /** @param array{name: string, description?: string|null, visibility?: string, is_discoverable?: bool, photo?: UploadedFile|null} $data */
    public function create(User $creator, array $data): Group
    {
        return DB::transaction(function () use ($creator, $data): Group {
            $group = Group::create([
                'creator_id' => $creator->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'] ?? 'public',
                'is_discoverable' => $data['is_discoverable'] ?? true,
                'member_count' => 1,
            ]);

            // Needs the group's own id for the storage directory, so this can only happen
            // after Group::create() above — same "create first, then store the file under
            // its id" ordering as posts' own media pipeline.
            if (! empty($data['photo'])) {
                $stored = $this->images->storeOriginal($data['photo'], "groups/{$group->id}", maxDimension: 1024);
                $group->photo_path = $stored['path'];
                $group->save();
            }

            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $creator->id,
                'role' => 'admin',
            ]);
            $this->annotateViewer(collect([$group]), $creator);

            return $group;
        });
    }

    public function show(User $viewer, Group $group): Group
    {
        // 404, not 403 — hides a private group's existence entirely, same convention as
        // UserController::show's block check, rather than confirming "yes, it exists, but
        // you can't see it."
        abort_unless($group->visibility !== 'private' || $group->isMember($viewer), 404);

        $this->annotateViewer(collect([$group]), $viewer);

        return $group;
    }

    /** [$viaInvite] is true only when called from GroupInviteService::accept — a private
     * group can still be joined that way; self-service joins (the default) are refused for
     * a private group instead of silently succeeding. */
    public function join(User $user, Group $group, bool $viaInvite = false): void
    {
        abort_if($group->visibility === 'private' && ! $viaInvite, 403, 'This group requires an invite to join.');

        DB::transaction(function () use ($user, $group): void {
            $locked = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $existing = GroupMember::query()->where('group_id', $locked->id)->where('user_id', $user->id)->exists();
            if ($existing) {
                return;
            }
            GroupMember::create(['group_id' => $locked->id, 'user_id' => $user->id, 'role' => 'member']);
            $locked->increment('member_count');
        });
    }

    public function leave(User $user, Group $group): void
    {
        DB::transaction(function () use ($user, $group): void {
            $locked = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $deleted = GroupMember::query()->where('group_id', $locked->id)->where('user_id', $user->id)->delete();
            if ($deleted > 0) {
                $locked->decrement('member_count');
            }
        });
    }

    /** @return CursorPaginator<int, Post> */
    public function posts(User $viewer, Group $group, int $perPage = 20): CursorPaginator
    {
        // See show()'s kdoc — same 404-hides-existence treatment for a private group's posts.
        abort_unless($group->visibility !== 'private' || $group->isMember($viewer), 404);

        $visiblePostIds = Post::query()->visibleTo($viewer)->select('id');
        $visiblePostIds = $this->blocks->excludeBlocked($visiblePostIds, $viewer, 'user_id');

        /** @var CursorPaginator<int, Post> $paginator */
        $paginator = Post::query()
            ->joinSub(
                GroupPost::query()->where('group_id', $group->id)->select('id as group_post_id', 'post_id'),
                'group_post_pivot',
                'group_post_pivot.post_id',
                '=',
                'posts.id',
            )
            ->whereIn('posts.id', $visiblePostIds)
            ->with(['user', 'media', 'category'])
            // select() before withCount(), never after: withCount() appends its subqueries with
            // addSelect, and a later select() replaces the whole column list — silently dropping
            // them, so every post came back with a null likes_count/comments_count/reposts_count.
            ->select('posts.*', 'group_post_pivot.group_post_id')
            ->withCount(['likes', 'comments', 'reposts'])
            ->orderByDesc('group_post_pivot.group_post_id')
            ->cursorPaginate($perPage);

        return $paginator;
    }

    public function shareIntoGroup(User $user, Group $group, Post $post): GroupPost
    {
        abort_unless($group->isMember($user), 403);
        abort_unless($post->isVisibleTo($user) && ! $this->blocks->isBlockedEitherWay($user, $post->user), 404);

        /** @var GroupPost $groupPost */
        $groupPost = GroupPost::query()->firstOrCreate(
            ['group_id' => $group->id, 'post_id' => $post->id],
            ['shared_by_user_id' => $user->id],
        );

        return $groupPost;
    }

    /** @param Collection<int, Group> $groups */
    private function annotateViewer(Collection $groups, User $viewer): void
    {
        $memberships = GroupMember::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('user_id', $viewer->id)
            ->pluck('role', 'group_id');

        $groups->each(function (Group $group) use ($memberships): void {
            $role = $memberships->get($group->id);
            $group->is_member = $role !== null;
            $group->is_admin = $role === 'admin';
        });
    }
}
