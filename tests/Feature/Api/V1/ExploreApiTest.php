<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\ScreenshotCategory;
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

    public function test_explore_excludes_muted_authors(): void
    {
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$author->id}/mute")->assertNoContent();

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $this->getJson('/api/v1/explore')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_explore_excludes_suspended_authors(): void
    {
        $author = User::factory()->create(['is_active' => false]);
        $post = Post::factory()->for($author)->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $this->getJson('/api/v1/explore')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_explore_is_offset_paginated_by_page_number(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->with('trending:posts', 15, 30)->andReturn([(string) $post->id]);

        $response = $this->getJson('/api/v1/explore?page=2');

        $response->assertOk();
    }

    public function test_explore_can_be_filtered_by_category(): void
    {
        $memes = ScreenshotCategory::query()->create(['slug' => 'memes', 'name' => 'Memes']);
        $art = ScreenshotCategory::query()->create(['slug' => 'art', 'name' => 'Art']);
        $memePost = Post::factory()->create(['category_id' => $memes->id]);
        $artPost = Post::factory()->create(['category_id' => $art->id]);
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $memePost->id, (string) $artPost->id]);

        $response = $this->getJson('/api/v1/explore?category=memes')->assertOk();
        $this->assertSame([$memePost->id], $response->json('data.*.id'));
    }

    public function test_explore_can_be_filtered_by_country(): void
    {
        $libyanAuthor = User::factory()->create(['country_code' => 'LY']);
        $egyptianAuthor = User::factory()->create(['country_code' => 'EG']);
        $libyanPost = Post::factory()->for($libyanAuthor)->create();
        $egyptianPost = Post::factory()->for($egyptianAuthor)->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $libyanPost->id, (string) $egyptianPost->id]);

        $response = $this->getJson('/api/v1/explore?country=LY')->assertOk();
        $this->assertSame([$libyanPost->id], $response->json('data.*.id'));
    }

    public function test_explore_country_filter_is_case_insensitive(): void
    {
        $author = User::factory()->create(['country_code' => 'LY']);
        $post = Post::factory()->for($author)->create();
        Sanctum::actingAs(User::factory()->create());

        Redis::shouldReceive('zrevrange')->once()->andReturn([(string) $post->id]);

        $this->getJson('/api/v1/explore?country=ly')->assertOk()->assertJsonCount(1, 'data');
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
