<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/feed` used to hardcode 15 while the Android client's PagingConfig was built around 20, so
 * Paging's prefetch arithmetic was working off a page size it never received.
 */
class FeedPageSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_honours_a_requested_page_size(): void
    {
        $viewer = $this->viewerFollowingAnAuthorWithPosts(25);

        $this->getJson('/api/v1/feed/following?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    public function test_it_falls_back_to_the_previous_default_when_unasked(): void
    {
        $this->viewerFollowingAnAuthorWithPosts(25);

        $this->getJson('/api/v1/feed/following')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_it_rejects_a_page_size_outside_the_allowed_range(): void
    {
        $this->viewerFollowingAnAuthorWithPosts(3);

        $this->getJson('/api/v1/feed/following?per_page=0')->assertUnprocessable();
        $this->getJson('/api/v1/feed/following?per_page=31')->assertUnprocessable();
    }

    private function viewerFollowingAnAuthorWithPosts(int $count): User
    {
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $viewer->following()->attach($author->id);
        Post::factory()->count($count)->create(['user_id' => $author->id]);
        Sanctum::actingAs($viewer);

        return $viewer;
    }
}
