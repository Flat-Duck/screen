<?php

namespace Tests\Feature\Console;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RefreshTrendingPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scores_recent_posts_higher_for_more_engagement_and_publishes_to_redis(): void
    {
        $popular = Post::factory()->create(['created_at' => now()->subHours(2)]);
        User::factory()->count(5)->create()->each(
            fn (User $liker) => Like::create(['post_id' => $popular->id, 'user_id' => $liker->id])
        );
        Comment::factory()->count(2)->create(['post_id' => $popular->id]);

        $quiet = Post::factory()->create(['created_at' => now()->subHours(2)]);

        $captured = [];

        Redis::shouldReceive('del')->once()->with('trending:posts:building');
        Redis::shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, ...$members) use (&$captured): bool {
                $captured = $members;

                return $key === 'trending:posts:building';
            });
        Redis::shouldReceive('rename')->once()->with('trending:posts:building', 'trending:posts');
        Redis::shouldReceive('expire')->once()->with('trending:posts', 3600);

        $this->artisan('posts:refresh-trending')->assertExitCode(0);

        // $captured is a flat [score, id, score, id, ...] list — pull each post's score out.
        $scoresById = [];
        for ($i = 0; $i < count($captured); $i += 2) {
            $scoresById[$captured[$i + 1]] = $captured[$i];
        }

        $this->assertGreaterThan($scoresById[$quiet->id], $scoresById[$popular->id]);
    }

    public function test_it_excludes_posts_outside_the_trending_window(): void
    {
        $old = Post::factory()->create(['created_at' => now()->subDays(30)]);
        User::factory()->count(10)->create()->each(
            fn (User $liker) => Like::create(['post_id' => $old->id, 'user_id' => $liker->id])
        );

        $recent = Post::factory()->create(['created_at' => now()->subHour()]);

        $captured = [];

        Redis::shouldReceive('del')->once();
        Redis::shouldReceive('zadd')->once()->withArgs(function (string $key, ...$members) use (&$captured): bool {
            $captured = $members;

            return true;
        });
        Redis::shouldReceive('rename')->once();
        Redis::shouldReceive('expire')->once();

        $this->artisan('posts:refresh-trending')->assertExitCode(0);

        $this->assertContains($recent->id, $captured);
        $this->assertNotContains($old->id, $captured);
    }

    public function test_it_clears_the_set_when_no_posts_are_in_the_window(): void
    {
        Post::factory()->create(['created_at' => now()->subDays(30)]);

        Redis::shouldReceive('del')->twice()->with(Mockery::anyOf('trending:posts:building', 'trending:posts'));
        Redis::shouldReceive('zadd')->never();
        Redis::shouldReceive('rename')->never();

        $this->artisan('posts:refresh-trending')->assertExitCode(0);
    }
}
