<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CandidateSource;
use App\Models\Interest;
use App\Models\Post;
use App\Models\RecommendationFeedSession;
use App\Models\User;
use App\Services\Recommendations\OnboardingInterestCandidateSource;
use Database\Seeders\InterestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_selection_editing_and_skip_contract(): void
    {
        $this->seed(InterestSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $catalog = $this->getJson('/api/v1/onboarding/interests')->assertOk()
            ->assertJsonCount(16, 'data')
            ->assertJsonPath('onboarding.needs_selection', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'icon', 'description', 'is_selected', 'categories', 'hashtags']]]);
        $ids = collect($catalog->json('data'))->take(3)->pluck('id')->all();

        RecommendationFeedSession::query()->create([
            'request_id' => fake()->uuid(), 'user_id' => $user->id, 'ranking_version' => 'old',
            'items' => [], 'expires_at' => now()->addHour(),
        ]);
        $this->putJson('/api/v1/me/interests', ['interest_ids' => $ids])->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('onboarding.completed', true);
        $this->assertDatabaseCount('interest_user', 3);
        $this->assertDatabaseCount('recommendation_feed_sessions', 0);
        $this->assertNotNull($user->fresh()->interests_completed_at);

        $this->getJson('/api/v1/onboarding/interests')->assertJsonPath('onboarding.needs_selection', false);
        $this->postJson('/api/v1/onboarding/interests/skip')->assertNoContent();
        $this->assertNull($user->fresh()->interests_skipped_at);
    }

    public function test_selection_requires_three_to_ten_distinct_active_interests(): void
    {
        $this->seed(InterestSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $interest = Interest::query()->firstOrFail();

        $this->putJson('/api/v1/me/interests', ['interest_ids' => [$interest->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('interest_ids');
        $interest->update(['is_active' => false]);
        $this->putJson('/api/v1/me/interests', ['interest_ids' => [$interest->id, 2, 3]])
            ->assertUnprocessable()->assertJsonValidationErrors('interest_ids.0');
    }

    public function test_skipping_finishes_onboarding_without_creating_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/onboarding/interests/skip')->assertNoContent();

        $this->assertNotNull($user->fresh()->interests_skipped_at);
        $this->assertDatabaseCount('interest_user', 0);
    }

    public function test_selected_interest_produces_cold_start_candidates(): void
    {
        $this->seed(InterestSeeder::class);
        $viewer = User::factory()->create();
        $interest = Interest::query()->where('slug', 'technology')->firstOrFail();
        $viewer->interests()->attach($interest, ['weight' => 100, 'source' => 'onboarding', 'selected_at' => now()]);
        $category = $interest->categories()->firstOrFail();
        $matching = Post::factory()->create(['category_id' => $category->id]);
        Post::factory()->create();

        $candidates = app(OnboardingInterestCandidateSource::class)->generate($viewer, 10);

        $this->assertContains($matching->id, $candidates->pluck('postId'));
        $this->assertSame(CandidateSource::OnboardingInterest, $candidates->firstOrFail()->source);
        $this->assertTrue($candidates->firstOrFail()->eligibility['explicit_interest']);
    }

    public function test_recommendation_reset_preserves_explicit_interests_unless_requested(): void
    {
        $this->seed(InterestSeeder::class);
        $user = User::factory()->create(['interests_completed_at' => now()]);
        $user->interests()->attach(Interest::query()->take(3)->pluck('id'), ['weight' => 100, 'source' => 'onboarding', 'selected_at' => now()]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/recommendations/profile')->assertNoContent();
        $this->assertDatabaseCount('interest_user', 3);
        $this->deleteJson('/api/v1/recommendations/profile', ['clear_interests' => true])->assertNoContent();
        $this->assertDatabaseCount('interest_user', 0);
        $this->assertTrue($user->fresh()->needsInterestOnboarding());
    }
}
