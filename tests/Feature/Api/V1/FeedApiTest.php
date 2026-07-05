<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
