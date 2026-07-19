<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlockApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocking_a_user_creates_a_block(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$target->id}/block");

        $response->assertNoContent();
        $this->assertDatabaseHas('blocks', ['blocker_id' => $user->id, 'blocked_id' => $target->id]);
    }

    public function test_a_user_cannot_block_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$user->id}/block");

        $response->assertUnprocessable();
    }

    public function test_blocking_severs_an_existing_follow_relationship_both_ways(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $user->following()->attach($target->id);
        $target->following()->attach($user->id);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        $this->assertDatabaseMissing('follows', ['follower_id' => $user->id, 'followee_id' => $target->id]);
        $this->assertDatabaseMissing('follows', ['follower_id' => $target->id, 'followee_id' => $user->id]);
    }

    public function test_unblocking_is_idempotent(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/users/{$target->id}/block")->assertNoContent();
        $response = $this->deleteJson("/api/v1/users/{$target->id}/block");

        $response->assertNoContent();
        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_blocked_users_list_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        $blockedOne = User::factory()->create();
        $blockedTwo = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$blockedOne->id}/block")->assertNoContent();
        $this->postJson("/api/v1/users/{$blockedTwo->id}/block")->assertNoContent();

        $response = $this->getJson('/api/v1/blocked-users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_a_blocked_users_profile_is_hidden_from_the_blocker(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        $this->getJson("/api/v1/users/{$target->id}")->assertNotFound();
    }

    public function test_a_blockers_profile_is_hidden_from_the_blocked_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        Sanctum::actingAs($target);
        $this->getJson("/api/v1/users/{$user->id}")->assertNotFound();
    }

    public function test_a_blocked_users_post_is_hidden_from_the_blocker(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $post = Post::factory()->for($target)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        $this->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
    }

    public function test_a_blocked_user_cannot_follow_the_blocker(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        Sanctum::actingAs($target);
        $response = $this->postJson("/api/v1/users/{$user->id}/follow");

        $response->assertForbidden();
    }

    public function test_a_blocked_user_cannot_like_the_blockers_post(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $post = Post::factory()->for($user)->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        Sanctum::actingAs($target);
        $response = $this->postJson("/api/v1/posts/{$post->id}/like");

        $response->assertForbidden();
    }

    public function test_a_blocked_user_cannot_comment_on_the_blockers_post(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $post = Post::factory()->for($user)->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        Sanctum::actingAs($target);
        $response = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'hello']);

        $response->assertForbidden();
    }

    public function test_a_blocked_users_posts_are_excluded_from_the_blockers_feed(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $user->following()->attach($target->id);
        Post::factory()->for($target)->create();
        Sanctum::actingAs($user);

        // Following the target would normally surface their post in the feed — block first.
        $this->postJson("/api/v1/users/{$target->id}/block")->assertNoContent();

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
