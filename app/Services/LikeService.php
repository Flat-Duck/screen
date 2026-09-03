<?php

namespace App\Services;

use App\Enums\UserModerationState;
use App\Enums\UserVisibilityState;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLikedNotification;
use App\Notifications\PostLikedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `likes` is polymorphic (likeable_type/likeable_id) so posts and comments share one
 * table/service instead of a parallel comment_likes table — mirrors how `reports` already
 * spans post/comment/user via reportable_type/reportable_id.
 */
class LikeService
{
    /** How many faces the clients' "liked by" row shows — see PostCardView on Android. */
    public const LIKE_PREVIEW_LIMIT = 3;

    public function __construct(
        private readonly MuteService $mutes,
        private readonly BlockService $blocks,
    ) {}

    /** Idempotent — backed by the (likeable_type, likeable_id, user_id) unique constraint as a race-condition backstop. */
    public function like(User $user, Post|Comment $likeable): void
    {
        $like = Like::query()->firstOrCreate([
            'likeable_type' => $likeable::class,
            'likeable_id' => $likeable->id,
            'user_id' => $user->id,
        ]);

        if (! $like->wasRecentlyCreated) {
            return;
        }

        $owner = $likeable->user;

        if ($user->isNot($owner) && $this->mutes->shouldNotify($owner, $user)) {
            $owner->notify($this->notificationFor($likeable, $user));
        }
    }

    /** Idempotent — unliking something you haven't liked is a no-op. */
    public function unlike(User $user, Post|Comment $likeable): void
    {
        Like::query()
            ->where('likeable_type', $likeable::class)
            ->where('likeable_id', $likeable->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Sets `is_liked` and `like_preview` on each post for the given viewer, two queries for the
     * whole page rather than per post.
     *
     * The preview exists so a client can draw real faces on its "liked by" row without a second
     * request per post — there is deliberately no `GET /posts/{post}/likes` endpoint, and adding
     * one would mean one call per card on screen.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function annotateLikes(Collection $posts, User $viewer): void
    {
        $this->annotateLikeableCollection($posts, $viewer, Post::class);
        $this->annotateLikePreviews($posts, $viewer);
    }

    /**
     * Attaches up to [self::LIKE_PREVIEW_LIMIT] recent likers to each post as `like_preview`.
     *
     * Picks the top N *per post* with a window function rather than fetching every like for the
     * page — a single popular post would otherwise pull thousands of rows to show three faces.
     * Hidden, deactivated, moderated and blocked-either-way users are excluded inside the window,
     * not after it, so a post whose three newest likers are all invisible to this viewer still
     * shows three faces it is allowed to show rather than an empty row.
     *
     * @param  Collection<int, Post>  $posts
     */
    private function annotateLikePreviews(Collection $posts, User $viewer): void
    {
        $postIds = $posts->pluck('id')->all();

        if ($postIds === []) {
            return;
        }

        $visibleLikers = Like::query()
            ->join('users', 'users.id', '=', 'likes.user_id')
            ->where('likes.likeable_type', Post::class)
            ->whereIn('likes.likeable_id', $postIds)
            ->where('users.is_active', true)
            ->where('users.visibility_state', UserVisibilityState::Visible->value)
            ->where('users.moderation_state', UserModerationState::Clear->value)
            ->select('likes.likeable_id as post_id', 'likes.user_id')
            ->selectRaw('row_number() over (partition by likes.likeable_id order by likes.id desc) as preview_rank');

        $visibleLikers = $this->blocks->excludeBlocked($visibleLikers, $viewer, 'likes.user_id');

        $ranked = DB::query()
            ->fromSub($visibleLikers, 'ranked')
            ->where('preview_rank', '<=', self::LIKE_PREVIEW_LIMIT)
            ->get();

        /** @var Collection<int, User> $likers */
        $likers = User::query()->whereIn('id', $ranked->pluck('user_id')->unique())->get()->keyBy('id');

        $byPost = $ranked
            ->groupBy('post_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->sortBy('preview_rank')
                ->map(fn (object $row): ?User => $likers->get($row->user_id))
                ->filter()
                ->values());

        $posts->each(function (Post $post) use ($byPost): void {
            $post->like_preview = $byPost->get($post->id, collect())->values();
        });
    }

    /**
     * Same as annotateLikes()'s is_liked half, but for a collection of comments. Comments have
     * no liker preview — nothing renders a facepile on a comment row.
     *
     * @param  Collection<int, Comment>  $comments
     */
    public function annotateCommentsAreLiked(Collection $comments, User $viewer): void
    {
        $this->annotateLikeableCollection($comments, $viewer, Comment::class);
    }

    /**
     * @template TItem of Post|Comment
     *
     * @param  Collection<int, TItem>  $items
     * @param  class-string<TItem>  $class
     */
    private function annotateLikeableCollection(Collection $items, User $viewer, string $class): void
    {
        $likedIds = Like::query()
            ->where('user_id', $viewer->id)
            ->where('likeable_type', $class)
            ->whereIn('likeable_id', $items->pluck('id'))
            ->pluck('likeable_id')
            ->all();

        $items->each(function (Post|Comment $item) use ($likedIds): void {
            $item->is_liked = in_array($item->id, $likedIds, true);
        });
    }

    private function notificationFor(Post|Comment $likeable, User $liker): Notification
    {
        return $likeable instanceof Post
            ? new PostLikedNotification($likeable, $liker)
            : new CommentLikedNotification($likeable, $liker);
    }
}
