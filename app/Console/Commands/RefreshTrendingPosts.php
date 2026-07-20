<?php

namespace App\Console\Commands;

use App\Enums\AccountVisibility;
use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Recomputes a Hacker-News-style "engagement decayed by age" score for recent posts and
 * publishes them to a Redis sorted set — the whole "ranking" stage of this app's scaled-down
 * take on a recommendation pipeline. See config('social.trending') and
 * FeedService::discoveryCandidates() for how it's consumed.
 */
class RefreshTrendingPosts extends Command
{
    /** @var string */
    protected $signature = 'posts:refresh-trending';

    /** @var string */
    protected $description = 'Recomputes trending post scores into Redis for feed discovery blending.';

    public function handle(): int
    {
        $windowDays = (int) config('social.trending.window_days', 7);
        $likeWeight = (float) config('social.trending.like_weight', 3);
        $commentWeight = (float) config('social.trending.comment_weight', 5);
        $gravity = (float) config('social.trending.gravity', 1.8);
        $redisKey = (string) config('social.trending.redis_key', 'trending:posts');
        $safetyTtlMinutes = (int) config('social.trending.safety_ttl_minutes', 60);

        $tempKey = "{$redisKey}:building";

        try {
            Redis::del($tempKey);

            $scored = 0;

            Post::query()
                ->where('recommendation_eligible', true)
                ->fromPubliclyVisibleAuthors()
                ->whereIn('user_id', User::query()->where('account_visibility', AccountVisibility::Public)->select('id'))
                ->where('created_at', '>=', now()->subDays($windowDays))
                ->withCount(['likes', 'comments'])
                ->chunkById(500, function ($posts) use ($tempKey, $likeWeight, $commentWeight, $gravity, &$scored): void {
                    $members = [];

                    foreach ($posts as $post) {
                        $ageHours = max(0, $post->created_at->diffInMinutes(now()) / 60);
                        $engagement = $post->likes_count * $likeWeight + $post->comments_count * $commentWeight;
                        $members[] = $engagement / (($ageHours + 2) ** $gravity);
                        $members[] = $post->id;
                    }

                    Redis::zadd($tempKey, ...$members);
                    $scored += $posts->count();
                });

            if ($scored === 0) {
                // $tempKey was already cleared above and never repopulated (no chunks ran).
                Redis::del($redisKey);
                $this->info('No posts in the trending window; cleared the trending set.');

                return self::SUCCESS;
            }

            // Build under a temp key and rename into place atomically, so readers never see a
            // half-populated set while this command is mid-run.
            Redis::rename($tempKey, $redisKey);
            Redis::expire($redisKey, $safetyTtlMinutes * 60);

            $this->info("Refreshed trending scores for {$scored} post(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error("Failed to refresh trending posts: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
