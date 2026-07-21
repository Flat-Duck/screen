<?php

namespace Tests\Feature;

use App\Enums\CandidateSource;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\ScreenshotCategory;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserTopicAffinity;
use App\Services\Recommendations\CandidateEligibilityService;
use App\Services\Recommendations\CandidateGenerationService;
use App\Services\Recommendations\CategoryCandidateSource;
use App\Services\Recommendations\FollowingCandidateSource;
use App\Services\Recommendations\InterestCandidateSource;
use App\Services\Recommendations\NewCreatorCandidateSource;
use App\Services\Recommendations\RegionalTrendingCandidateSource;
use App\Services\Recommendations\SimilarAuthorCandidateSource;
use App\Services\Recommendations\SimilarTopicCandidateSource;
use App\Services\Recommendations\TrendingCandidateSource;
use App\Services\Recommendations\TwoHopCandidateSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RecommendationCandidateGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_following_candidates_are_bounded_and_apply_all_hard_eligibility_rules(): void
    {
        config(['social.recommendations.windows.following_days' => 14]);
        $viewer = User::factory()->create();
        $allowed = User::factory()->create();
        $blocked = User::factory()->create();
        $muted = User::factory()->create();
        $viewer->following()->attach([$allowed->id, $blocked->id, $muted->id]);
        $eligible = Post::factory()->for($allowed)->create();
        Post::factory()->for($allowed)->create(['recommendation_eligible' => false]);
        $unsafe = Post::factory()->for($allowed)->create();
        PostMedia::factory()->for($unsafe)->create(['safety_status' => PostMedia::SAFETY_WARNING]);
        Post::factory()->for($blocked)->create();
        Post::factory()->for($muted)->create();
        DB::table('blocks')->insert(['blocker_id' => $viewer->id, 'blocked_id' => $blocked->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mutes')->insert(['muter_id' => $viewer->id, 'muted_id' => $muted->id, 'created_at' => now(), 'updated_at' => now()]);

        $candidates = app(FollowingCandidateSource::class)->generate($viewer, 10);

        $this->assertSame([$eligible->id], $candidates->pluck('postId')->all());
        $this->assertSame(CandidateSource::Following, $candidates->firstOrFail()->source);
        $this->assertArrayHasKey('author_id', $candidates->firstOrFail()->eligibility);
    }

    public function test_interest_graph_and_affinity_sources_produce_deterministic_candidates(): void
    {
        $viewer = User::factory()->create();
        $category = ScreenshotCategory::query()->firstOrFail();
        $hashtag = Hashtag::factory()->create();
        $viewer->followedHashtags()->attach($hashtag);

        $hashtagPost = Post::factory()->create();
        $hashtagPost->hashtags()->attach($hashtag);
        $categoryPost = Post::factory()->create(['category_id' => $category->id]);
        UserTopicAffinity::create(['affinity_date' => today(), 'user_id' => $viewer->id, 'category_id' => $category->id, 'score' => 8, 'last_event_at' => now()]);

        $bridge = User::factory()->create();
        $twoHopAuthor = User::factory()->create();
        $viewer->following()->attach($bridge);
        $bridge->following()->attach($twoHopAuthor);
        $twoHopPost = Post::factory()->for($twoHopAuthor)->create();

        $affinityAuthor = User::factory()->create();
        $affinityPost = Post::factory()->for($affinityAuthor)->create();
        UserAuthorAffinity::create(['affinity_date' => today(), 'user_id' => $viewer->id, 'author_id' => $affinityAuthor->id, 'score' => 6, 'last_event_at' => now()]);

        $this->assertContains($hashtagPost->id, app(InterestCandidateSource::class)->generate($viewer, 10)->pluck('postId'));
        $this->assertContains($categoryPost->id, app(CategoryCandidateSource::class)->generate($viewer, 10)->pluck('postId'));
        $this->assertContains($twoHopPost->id, app(TwoHopCandidateSource::class)->generate($viewer, 10)->pluck('postId'));
        $this->assertContains($affinityPost->id, app(SimilarAuthorCandidateSource::class)->generate($viewer, 10)->pluck('postId'));
        $this->assertContains($categoryPost->id, app(SimilarTopicCandidateSource::class)->generate($viewer, 10)->pluck('postId'));
    }

    public function test_hot_pool_sources_preserve_redis_order_and_fallback_to_database(): void
    {
        $viewer = User::factory()->create(['country_code' => 'LY']);
        $author = User::factory()->create(['country_code' => 'LY']);
        $first = Post::factory()->for($author)->create();
        $second = Post::factory()->for($author)->create();

        Redis::shouldReceive('zrevrange')->once()->with('recommendations:v1:hot:global', 0, 5)
            ->andReturn([(string) $second->id, (string) $first->id]);
        $global = app(TrendingCandidateSource::class)->generate($viewer, 2);
        $this->assertSame([$second->id, $first->id], $global->pluck('postId')->all());

        Redis::shouldReceive('zrevrange')->once()->with('recommendations:v1:hot:country:ly', 0, 5)
            ->andThrow(new \RuntimeException('Redis unavailable'));
        $regional = app(RegionalTrendingCandidateSource::class)->generate($viewer, 2);
        $this->assertCount(2, $regional);
        $this->assertSame(CandidateSource::RegionalTrending, $regional->firstOrFail()->source);
    }

    public function test_new_creator_source_is_bounded(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create(['created_at' => now()->subDay()]);
        Post::factory()->count(3)->for($creator)->create();

        $candidates = app(NewCreatorCandidateSource::class)->generate($viewer, 2);

        $this->assertCount(2, $candidates);
        $this->assertSame(CandidateSource::NewCreator, $candidates->firstOrFail()->source);
    }

    public function test_orchestrator_deduplicates_posts_and_preserves_source_provenance(): void
    {
        config([
            'social.recommendations.total_limit' => 10,
            'social.recommendations.source_limits' => [
                'following' => 10, 'followed_hashtag' => 0, 'category' => 10, 'trending' => 0,
                'regional_trending' => 0, 'two_hop' => 0, 'similar_author' => 0,
                'similar_topic' => 0, 'new_creator' => 0,
            ],
        ]);
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $viewer->following()->attach($author);
        $category = ScreenshotCategory::query()->firstOrFail();
        UserTopicAffinity::create(['affinity_date' => today(), 'user_id' => $viewer->id, 'category_id' => $category->id, 'score' => 8, 'last_event_at' => now()]);
        $post = Post::factory()->for($author)->create(['category_id' => $category->id]);

        $candidates = app(CandidateGenerationService::class)->generate($viewer);

        $this->assertSame([$post->id], $candidates->pluck('postId')->all());
        $this->assertSame(['category'], $candidates->firstOrFail()->eligibility['additional_sources']);
    }

    public function test_eligibility_query_allows_followed_private_authors_but_not_public_discovery(): void
    {
        $viewer = User::factory()->create();
        $private = User::factory()->create(['account_visibility' => 'private']);
        $viewer->following()->attach($private);
        $post = Post::factory()->for($private)->create();

        $this->assertTrue(app(CandidateEligibilityService::class)->query($viewer)->whereKey($post)->exists());
        $this->assertFalse(app(CandidateEligibilityService::class)->query($viewer, publicOnly: true)->whereKey($post)->exists());
    }
}
