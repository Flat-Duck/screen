<?php

namespace Tests\Feature\Api\V1;

use App\Data\Recommendations\RecommendationCandidate;
use App\Enums\CandidateSource;
use App\Models\Post;
use App\Models\RecommendationFeedSession;
use App\Models\User;
use App\Services\Recommendations\RecommendationRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalizedFeedApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'social.recommendations.source_limits' => [
                'following' => 100, 'followed_hashtag' => 0, 'category' => 0, 'trending' => 0,
                'regional_trending' => 0, 'two_hop' => 0, 'similar_author' => 0,
                'similar_topic' => 0, 'new_creator' => 0,
            ],
        ]);
    }

    public function test_for_you_returns_recommendation_metadata_and_stable_pages(): void
    {
        $viewer = User::factory()->create();
        $authors = User::factory()->count(5)->create();
        $authors->each(fn (User $author) => $viewer->following()->attach($author));
        $posts = $authors->map(fn (User $author) => Post::factory()->for($author)->create());
        Sanctum::actingAs($viewer);

        $first = $this->getJson('/api/v1/feed/for-you?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.recommendation.source', 'following')
            ->assertJsonStructure(['meta' => ['feed_session_id', 'request_id', 'next_cursor', 'has_more']]);
        $cursor = $first->json('meta.next_cursor');
        $second = $this->getJson('/api/v1/feed/for-you?per_page=2&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(2, 'data');
        $third = $this->getJson('/api/v1/feed/for-you?per_page=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonCount(1, 'data');

        $ids = collect([$first, $second, $third])->flatMap(fn ($response) => $response->json('data'))->pluck('id');
        $this->assertCount(5, $ids->unique());
        $this->assertEqualsCanonicalizing($posts->pluck('id')->all(), $ids->all());
        $this->assertSame($first->json('meta.request_id'), $second->json('meta.request_id'));
        $this->assertDatabaseCount('recommendation_feed_sessions', 1);
    }

    public function test_hard_filter_changes_override_an_existing_session(): void
    {
        $viewer = User::factory()->create();
        $authors = User::factory()->count(2)->create();
        $authors->each(fn (User $author) => $viewer->following()->attach($author));
        $authors->each(fn (User $author) => Post::factory()->for($author)->create());
        Sanctum::actingAs($viewer);

        $first = $this->getJson('/api/v1/feed/for-you?per_page=1')->assertOk();
        $session = RecommendationFeedSession::firstOrFail();
        $secondPostId = $session->items[1]['post_id'];
        $secondAuthorId = Post::findOrFail($secondPostId)->user_id;
        DB::table('blocks')->insert(['blocker_id' => $viewer->id, 'blocked_id' => $secondAuthorId, 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/v1/feed/for-you?per_page=1&cursor='.urlencode($first->json('meta.next_cursor')))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cursor_is_bound_to_its_user_and_expiration(): void
    {
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $viewer->following()->attach($author);
        Post::factory()->count(2)->for($author)->create();
        Sanctum::actingAs($viewer);
        $cursor = $this->getJson('/api/v1/feed/for-you?per_page=1')->json('meta.next_cursor');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/feed/for-you?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonValidationErrors('cursor');
        Sanctum::actingAs($viewer);
        RecommendationFeedSession::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/feed/for-you?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    public function test_following_feed_is_chronological_and_has_no_recommendation_metadata(): void
    {
        $viewer = User::factory()->create();
        $followed = User::factory()->create();
        $stranger = User::factory()->create();
        $viewer->following()->attach($followed);
        $post = Post::factory()->for($followed)->create();
        Post::factory()->for($stranger)->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/feed/following')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)->assertJsonMissingPath('data.0.recommendation');
    }

    public function test_scoring_is_deterministic_and_diversifies_when_alternatives_exist(): void
    {
        config(['social.recommendations.page_size' => 6, 'social.recommendations.diversity.max_per_author' => 2]);
        $viewer = User::factory()->create();
        $dominant = User::factory()->create(['created_at' => now()->subYear()]);
        $alternative = User::factory()->create(['created_at' => now()->subYear()]);
        $posts = collect([
            ...Post::factory()->count(4)->for($dominant)->create(),
            ...Post::factory()->count(2)->for($alternative)->create(),
        ]);
        $candidates = $posts->values()->map(fn (Post $post, int $index) => new RecommendationCandidate(
            $post->id,
            CandidateSource::Trending,
            $index < 4 ? 1.0 - $index / 100 : 0.5,
            now(),
            ['author_id' => $post->user_id, 'category_id' => null],
        ));
        $ranking = app(RecommendationRankingService::class);

        $first = $ranking->rank($viewer, $candidates);
        $second = $ranking->rank($viewer, $candidates);

        $this->assertSame($first->pluck('postId')->all(), $second->pluck('postId')->all());
        $firstThreeAuthors = Post::query()->whereIn('id', $first->take(3)->pluck('postId'))->pluck('user_id');
        $this->assertSame(2, $firstThreeAuthors->filter(fn (int $id): bool => $id === $dominant->id)->count());
        $this->assertArrayHasKey('already_seen', $first->firstOrFail()->components);
    }

    public function test_cold_start_returns_an_empty_but_valid_feed_session(): void
    {
        config(['social.recommendations.source_limits' => array_fill_keys([
            'following', 'followed_hashtag', 'category', 'trending', 'regional_trending',
            'two_hop', 'similar_author', 'similar_topic', 'new_creator',
        ], 0)]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/feed/for-you')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);
    }

    public function test_for_you_falls_back_to_bounded_database_trending_when_redis_is_down(): void
    {
        config(['social.recommendations.source_limits' => [
            'following' => 0, 'followed_hashtag' => 0, 'category' => 0, 'trending' => 50,
            'regional_trending' => 0, 'two_hop' => 0, 'similar_author' => 0,
            'similar_topic' => 0, 'new_creator' => 0,
        ]]);
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        Redis::shouldReceive('zrevrange')->once()->with('recommendations:v1:hot:global', 0, 149)
            ->andThrow(new \RuntimeException('Redis unavailable'));
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/feed/for-you')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.recommendation.source', 'trending');
    }

    public function test_expired_feed_sessions_are_pruned(): void
    {
        $user = User::factory()->create();
        RecommendationFeedSession::create([
            'request_id' => fake()->uuid(), 'user_id' => $user->id, 'ranking_version' => 'v1',
            'items' => [], 'expires_at' => now()->subMinute(),
        ]);
        RecommendationFeedSession::create([
            'request_id' => fake()->uuid(), 'user_id' => $user->id, 'ranking_version' => 'v1',
            'items' => [], 'expires_at' => now()->addMinute(),
        ]);

        $this->artisan('recommendations:prune-sessions')->assertSuccessful();

        $this->assertDatabaseCount('recommendation_feed_sessions', 1);
    }
}
