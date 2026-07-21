<?php

namespace Tests\Feature;

use App\Data\Recommendations\RecommendationCandidate;
use App\Enums\AdminRole;
use App\Enums\CandidateSource;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\RecommendationFeedSession;
use App\Models\RecommendationPostFeedback;
use App\Models\RecommendationTargetFeedback;
use App\Models\ScreenshotCategory;
use App\Models\User;
use App\Models\UserAuthorAffinity;
use App\Models\UserTopicAffinity;
use App\Services\Recommendations\CandidateEligibilityService;
use App\Services\Recommendations\RecommendationRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationFeedbackAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_feedback_is_idempotent_reversible_and_private_to_the_actor(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $post = Post::factory()->create();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/posts/{$post->id}/not-interested")->assertNoContent();
        $this->postJson("/api/v1/posts/{$post->id}/not-interested")->assertNoContent();
        $this->assertDatabaseCount('recommendation_post_feedback', 1);
        $this->assertFalse(app(CandidateEligibilityService::class)->query($actor)->whereKey($post)->exists());
        $this->assertTrue(app(CandidateEligibilityService::class)->query($other)->whereKey($post)->exists());

        $this->deleteJson("/api/v1/posts/{$post->id}/not-interested")->assertNoContent();
        $this->assertTrue(app(CandidateEligibilityService::class)->query($actor)->whereKey($post)->exists());
        $this->postJson("/api/v1/posts/{$post->id}/hide")->assertNoContent();
        $this->assertDatabaseHas('recommendation_post_feedback', ['user_id' => $actor->id, 'post_id' => $post->id, 'type' => 'hidden']);
    }

    public function test_show_fewer_targets_are_user_local_and_invalidate_feed_snapshots(): void
    {
        $actor = User::factory()->create();
        $author = User::factory()->create();
        $hashtag = Hashtag::factory()->create();
        RecommendationFeedSession::create([
            'request_id' => fake()->uuid(), 'user_id' => $actor->id, 'ranking_version' => 'v1', 'items' => [], 'expires_at' => now()->addHour(),
        ]);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/users/{$author->id}/show-fewer")->assertNoContent();
        $this->postJson("/api/v1/hashtags/{$hashtag->name}/show-fewer")->assertNoContent();

        $this->assertDatabaseHas('recommendation_target_feedback', ['user_id' => $actor->id, 'target_type' => 'author', 'target_id' => $author->id]);
        $this->assertDatabaseHas('recommendation_target_feedback', ['user_id' => $actor->id, 'target_type' => 'hashtag', 'target_id' => $hashtag->id]);
        $this->assertDatabaseCount('recommendation_feed_sessions', 0);
    }

    public function test_show_fewer_applies_explainable_author_and_hashtag_penalties(): void
    {
        $viewer = User::factory()->create();
        $author = User::factory()->create(['created_at' => now()->subYear()]);
        $hashtag = Hashtag::factory()->create();
        $post = Post::factory()->for($author)->create();
        $post->hashtags()->attach($hashtag);
        $candidate = new RecommendationCandidate($post->id, CandidateSource::Trending, 1, now(), [
            'author_id' => $author->id, 'category_id' => null,
        ]);
        $ranking = app(RecommendationRankingService::class);
        $before = $ranking->rank($viewer, collect([$candidate]))->firstOrFail();
        RecommendationTargetFeedback::create(['user_id' => $viewer->id, 'target_type' => 'author', 'target_id' => $author->id]);
        RecommendationTargetFeedback::create(['user_id' => $viewer->id, 'target_type' => 'hashtag', 'target_id' => $hashtag->id]);

        $after = $ranking->rank($viewer, collect([$candidate]))->firstOrFail();

        $this->assertSame(-22.0, $after->components['show_fewer']);
        $this->assertEqualsWithDelta(22.0, $before->score - $after->score, 0.00001);
    }

    public function test_reset_removes_only_recommendation_profile_and_behavioral_history(): void
    {
        $user = User::factory()->create();
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $category = ScreenshotCategory::query()->firstOrFail();
        RecommendationPostFeedback::create(['user_id' => $user->id, 'post_id' => $post->id, 'type' => 'hidden']);
        RecommendationTargetFeedback::create(['user_id' => $user->id, 'target_type' => 'author', 'target_id' => $author->id]);
        UserAuthorAffinity::create(['affinity_date' => today(), 'user_id' => $user->id, 'author_id' => $author->id, 'score' => 5, 'last_event_at' => now()]);
        UserTopicAffinity::create(['affinity_date' => today(), 'user_id' => $user->id, 'category_id' => $category->id, 'score' => 5, 'last_event_at' => now()]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/recommendations/profile')->assertNoContent();

        $this->assertDatabaseCount('recommendation_post_feedback', 0);
        $this->assertDatabaseCount('recommendation_target_feedback', 0);
        $this->assertDatabaseCount('user_author_affinities', 0);
        $this->assertDatabaseCount('user_topic_affinities', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_admin_exclusions_are_global_permission_gated_and_audited(): void
    {
        $post = Post::factory()->create();
        $viewer = User::factory()->create();
        $auditor = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::ReadOnlyAuditor]);
        $this->actingAs($auditor)->get(route('recommendations.index'))->assertOk();
        $this->post(route('recommendations.exclude', $post), ['reason' => 'Global quality issue'])->assertForbidden();

        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $this->actingAs($moderator)->post(route('recommendations.exclude', $post), [
            'reason' => 'Confirmed recommendation manipulation', 'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertFalse(app(CandidateEligibilityService::class)->query($viewer)->whereKey($post)->exists());
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $moderator->id, 'action' => 'recommendation_exclusion.created']);
    }

    public function test_global_kill_switch_does_not_break_following_feed(): void
    {
        config(['social.recommendations.source_limits' => [
            'following' => 10, 'followed_hashtag' => 0, 'category' => 0, 'trending' => 0,
            'regional_trending' => 0, 'two_hop' => 0, 'similar_author' => 0, 'similar_topic' => 0, 'new_creator' => 0,
        ]]);
        $moderator = User::factory()->create(['is_admin' => true, 'admin_role' => AdminRole::Moderator]);
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $viewer->following()->attach($author);
        Post::factory()->for($author)->create();

        $this->actingAs($moderator)->post(route('recommendations.serving'), ['enabled' => false, 'reason' => 'Emergency ranking shutdown'])->assertRedirect();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/feed/for-you')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/feed/following')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'feature_flag.configured', 'reason' => 'Emergency ranking shutdown']);
    }
}
