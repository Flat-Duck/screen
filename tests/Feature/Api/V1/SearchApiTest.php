<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AccountVisibility;
use App\Enums\UserVisibilityState;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_users_matches_username_or_name(): void
    {
        User::factory()->create(['username' => 'findme', 'name' => 'Someone']);
        User::factory()->create(['username' => 'other', 'name' => 'FindMe Also']);
        User::factory()->create(['username' => 'nomatch', 'name' => 'Nobody']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/users?q=findme');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_searching_users_excludes_inactive_accounts(): void
    {
        User::factory()->create(['username' => 'findme', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/users?q=findme');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_searching_users_excludes_the_viewer_themselves(): void
    {
        $viewer = User::factory()->create(['username' => 'findme']);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/search/users?q=findme');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_searching_users_excludes_blocked_either_way(): void
    {
        $target = User::factory()->create(['username' => 'findme']);
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        $response = $this->getJson('/api/v1/search/users?q=findme');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_searching_requires_a_query(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/users');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_searching_posts_matches_caption(): void
    {
        Post::factory()->create(['caption' => 'A screenshot bug report']);
        Post::factory()->create(['caption' => 'Unrelated']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/posts?q=bug');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_post_search_document_tracks_caption_edits(): void
    {
        $post = Post::factory()->create(['caption' => 'Original screenshot']);
        Sanctum::actingAs($post->user);

        $this->assertSame('Original screenshot', $post->searchable_text);

        $this->patchJson("/api/v1/posts/{$post->id}", ['caption' => 'Updated settings screen'])
            ->assertOk();

        $this->assertSame('Updated settings screen', $post->fresh()->searchable_text);
    }

    public function test_post_search_excludes_private_and_hidden_content_before_returning_results(): void
    {
        $privateAuthor = User::factory()->create(['account_visibility' => AccountVisibility::Private]);
        $hiddenAuthor = User::factory()->create(['visibility_state' => UserVisibilityState::Hidden]);
        Post::factory()->for($privateAuthor)->create(['caption' => 'confidential screenshot']);
        Post::factory()->for($hiddenAuthor)->create(['caption' => 'confidential screenshot']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/search/posts?q=confidential')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_uses_page_pagination_for_relevance_order(): void
    {
        Post::factory()->count(21)->create(['caption' => 'searchable screenshot']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/posts?q=searchable&page=2');

        $response->assertOk();
        $response->assertJsonPath('meta.current_page', 2);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonCount(1, 'data');
    }

    public function test_searching_hashtags_matches_by_name(): void
    {
        Hashtag::factory()->create(['name' => 'bug']);
        Hashtag::factory()->create(['name' => 'screenshot']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/hashtags?q=bug');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'bug');
    }

    public function test_searching_hashtags_is_case_insensitive(): void
    {
        Hashtag::factory()->create(['name' => 'bug']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/search/hashtags?q=BUG');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }
}
