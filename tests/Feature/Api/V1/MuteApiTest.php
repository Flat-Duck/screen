<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MuteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_muting_a_user_creates_a_mute(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$target->id}/mute");

        $response->assertNoContent();
        $this->assertDatabaseHas('mutes', ['muter_id' => $user->id, 'muted_id' => $target->id]);
    }

    public function test_a_user_cannot_mute_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$user->id}/mute");

        $response->assertUnprocessable();
    }

    public function test_unmuting_is_idempotent(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/users/{$target->id}/mute")->assertNoContent();
        $response = $this->deleteJson("/api/v1/users/{$target->id}/mute");

        $response->assertNoContent();
        $this->assertDatabaseCount('mutes', 0);
    }

    public function test_muted_users_list_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        $mutedOne = User::factory()->create();
        $mutedTwo = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$mutedOne->id}/mute")->assertNoContent();
        $this->postJson("/api/v1/users/{$mutedTwo->id}/mute")->assertNoContent();

        $response = $this->getJson('/api/v1/muted-users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_muting_does_not_prevent_the_muted_user_from_interacting(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $post = Post::factory()->for($user)->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/mute")->assertNoContent();

        // Muting is one-directional and doesn't restrict the muted user at all.
        Sanctum::actingAs($target);
        $this->postJson("/api/v1/users/{$user->id}/follow")->assertNoContent();
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
    }

    public function test_a_muted_users_posts_are_excluded_from_the_muters_feed_while_still_followed(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $user->following()->attach($target->id);
        Post::factory()->for($target)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$target->id}/mute")->assertNoContent();

        $response = $this->getJson('/api/v1/feed');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        // Still following — mute doesn't touch the follow graph.
        $this->assertDatabaseHas('follows', ['follower_id' => $user->id, 'followee_id' => $target->id]);
    }

    public function test_muting_suppresses_the_new_follower_notification(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$target->id}/mute")->assertNoContent();

        Sanctum::actingAs($target);
        $this->postJson("/api/v1/users/{$user->id}/follow")->assertNoContent();

        $this->assertDatabaseCount('notifications', 0);
    }
}
