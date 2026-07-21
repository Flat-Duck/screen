<?php

namespace App\Console\Commands;

use App\Enums\AccountVisibility;
use App\Enums\UserRestrictionType;
use App\Models\Post;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RefreshRecommendationPools extends Command
{
    protected $signature = 'recommendations:refresh-pools';

    protected $description = 'Refreshes versioned global and country recommendation hot pools.';

    public function handle(): int
    {
        $prefix = (string) config('social.recommendations.hot_pool_prefix', 'recommendations:v1:hot');
        $ttl = (int) config('social.recommendations.hot_pool_ttl_minutes', 60) * 60;
        $window = (int) config('social.recommendations.windows.trending_days', 7);

        try {
            $posts = Post::query()->where('recommendation_eligible', true)
                ->whereNotIn('user_id', UserRestriction::query()->active()->where('type', UserRestrictionType::Recommendation)->select('user_id'))
                ->fromPubliclyVisibleAuthors()
                ->whereIn('user_id', User::query()->where('account_visibility', AccountVisibility::Public->value)->select('id'))
                ->where('created_at', '>=', now()->subDays($window))
                ->with('user:id,country_code')->withCount(['likes', 'comments'])->get();

            $pools = ['global' => []];
            foreach ($posts as $post) {
                $ageHours = max(0, $post->created_at->diffInMinutes(now()) / 60);
                $score = ($post->likes_count * 3 + $post->comments_count * 5 + 1) / (($ageHours + 2) ** 1.8);
                $pools['global'][(string) $post->id] = $score;
                if ($post->user->country_code !== null) {
                    $pools['country:'.strtolower($post->user->country_code)][(string) $post->id] = $score;
                }
            }

            foreach ($pools as $suffix => $members) {
                $key = $prefix.':'.$suffix;
                $building = $key.':building';
                Redis::del($building);
                if ($members === []) {
                    Redis::del($key);

                    continue;
                }
                $arguments = [];
                foreach ($members as $postId => $score) {
                    $arguments[] = $score;
                    $arguments[] = $postId;
                }
                Redis::zadd($building, ...$arguments);
                Redis::rename($building, $key);
                Redis::expire($key, $ttl);
            }

            $this->info("Refreshed recommendation pools for {$posts->count()} post(s).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Failed to refresh recommendation pools: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
