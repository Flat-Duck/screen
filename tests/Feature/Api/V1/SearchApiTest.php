<?php

namespace Tests\Feature\Api\V1;

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
