<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupService
{
    public function __construct(
        private readonly BlockService $blocks,
    ) {}

    /** @return CursorPaginator<int, Group> */
    public function discover(User $viewer, ?string $query, bool $mine, int $perPage = 20): CursorPaginator
    {
        $groups = Group::query()
            ->when($query, fn (Builder $q) => $q->where('name', 'like', '%'.$query.'%'))
            ->when($mine, fn (Builder $q) => $q->whereIn('id', GroupMember::query()->where('user_id', $viewer->id)->select('group_id')))
            ->orderByDesc('member_count')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        $this->annotateViewer($groups->getCollection(), $viewer);

        return $groups;
    }

    /** @param array{name: string, description?: string|null, visibility?: string} $data */
    public function create(User $creator, array $data): Group
    {
        return DB::transaction(function () use ($creator, $data): Group {
            $group = Group::create([
                'creator_id' => $creator->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'] ?? 'public',
                'member_count' => 1,
            ]);
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
        $this->annotateViewer(collect([$group]), $viewer);

        return $group;
    }

    public function join(User $user, Group $group): void
    {
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
            ->withCount(['likes', 'comments'])
            ->select('posts.*', 'group_post_pivot.group_post_id')
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
