<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ExploreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_returns_posts_ranked_by_the_trending_pool(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore');

        $response->assertOk();
        $this->assertSame([$post->id], $response->json('data.*.id'));
    }

    public function test_explore_includes_posts_from_already_followed_authors(): void
    {
        $user = User::factory()->create();
        $followed = User::factory()->create();
        $user->following()->attach($followed->id);
        $post = Post::factory()->create(['user_id' => $followed->id]);
        Sanctum::actingAs($user);

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore');

        $response->assertOk();
        $this->assertSame([$post->id], $response->json('data.*.id'));
    }

    public function test_explore_excludes_the_viewers_own_posts(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_explore_excludes_blocked_either_way(): void
    {
        $blockedAuthor = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $blockedAuthor->id]);
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$blockedAuthor->id}/block")->assertNoContent();

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_explore_is_offset_paginated_by_page_number(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->with('trending:posts', 15, 30)->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore?page=2');

        $response->assertOk();
    }

    public function test_explore_degrades_gracefully_when_redis_is_unreachable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andThrow(new RuntimeException('Connection refused'));

        $response = $this->getJson('/api/v1/explore');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
