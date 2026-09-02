<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_only_shows_posts_from_followed_users(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $stranger = User::factory()->create();

        $user->following()->attach($followed->id);

        $followedPost = Post::factory()->create(['user_id' => $followed->id]);
        Post::factory()->create(['user_id' => $stranger->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($followedPost->id, $response->json('data.0.id'));
    }

    /**
     * Every author in the Following feed is, by definition, followed — but `UserSummaryResource`
     * carried no follow state, so the client had nothing to read and defaulted to "not following".
     * The result was a Follow button on every post in a feed built entirely from people the
     * viewer already follows.
     */
    public function test_following_feed_marks_authors_as_followed(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        Post::factory()->create(['user_id' => $followed->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $followed->id)
            ->assertJsonPath('data.0.user.is_following', true);
    }

    /** The viewer's own posts leave it absent rather than false — "following yourself" is not a
     * question, and the clients hide the button entirely on that signal. */
    public function test_own_post_omits_is_following_on_the_author(): void
    {
        $user = User::factory()->create();
        Post::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/posts/'.Post::query()->firstOrFail()->id);

        $response->assertOk();
        $this->assertArrayNotHasKey('is_following', $response->json('data.user'));
    }

    public function test_post_detail_marks_a_followed_author(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        $post = Post::factory()->create(['user_id' => $followed->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.user.is_following', true);
    }

    public function test_for_you_feed_marks_unfollowed_authors(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        Post::factory()->create(['user_id' => $stranger->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed/for-you');

        $response->assertOk();
        if ($response->json('data.0.user.id') === $stranger->id) {
            $this->assertFalse($response->json('data.0.user.is_following'));
        }
    }

    public function test_feed_excludes_the_viewers_own_posts(): void
    {
        $user = User::factory()->create();
        Post::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_feed_is_cursor_paginated_in_reverse_chronological_order(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);

        $older = Post::factory()->create(['user_id' => $followed->id]);
        $newer = Post::factory()->create(['user_id' => $followed->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $this->assertSame([$newer->id, $older->id], $response->json('data.*.id'));
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_first_page_blends_in_a_discovery_post_from_the_trending_pool(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        Post::factory()->create(['user_id' => $followed->id]);

        $discoveryPost = Post::factory()->create(['user_id' => User::factory()->create()->id]);

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $discoveryPost->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $this->assertContains($discoveryPost->id, $response->json('data.*.id'));
    }

    public function test_discovery_excludes_posts_from_already_followed_authors(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        $followedPost = Post::factory()->create(['user_id' => $followed->id]);

        // Redis "recommends" a post that's actually from someone already followed —
        // discoveryCandidates() must filter it back out rather than duplicate it.
        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $followedPost->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $this->assertSame([$followedPost->id], $response->json('data.*.id'));
    }

    public function test_only_the_first_page_queries_the_trending_pool(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        Post::factory()->count(20)->create(['user_id' => $followed->id]);

        // ->once() here is the actual assertion: if page 2 also queried Redis, Mockery
        // would fail this test for the unexpected extra call.
        Redis::shouldReceive('zrevrange')->once()->andReturn([]);

        Sanctum::actingAs($user);

        $first = $this->getJson('/api/v1/feed');
        $first->assertOk();
        $nextCursorUrl = $first->json('links.next');
        $this->assertNotNull($nextCursorUrl);

        $second = $this->getJson($nextCursorUrl);
        $second->assertOk();
    }

    public function test_feed_degrades_gracefully_when_redis_is_unreachable(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        $post = Post::factory()->create(['user_id' => $followed->id]);

        Redis::shouldReceive('zrevrange')->once()->andThrow(new \RuntimeException('Connection refused'));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($post->id, $response->json('data.0.id'));
    }
}
