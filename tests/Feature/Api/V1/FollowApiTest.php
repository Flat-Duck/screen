<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FollowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_following_a_user_creates_a_follow_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$target->id}/follow");

        $response->assertNoContent();
        $this->assertDatabaseHas('follows', ['follower_id' => $user->id, 'followee_id' => $target->id]);
    }

    public function test_a_user_cannot_follow_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/users/{$user->id}/follow");

        $response->assertUnprocessable();
    }

    public function test_unfollowing_is_idempotent(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/users/{$target->id}/follow")->assertNoContent();
        $response = $this->deleteJson("/api/v1/users/{$target->id}/follow");

        $response->assertNoContent();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_followers_list_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        $followerOne = User::factory()->create();
        $followerTwo = User::factory()->create();

        $followerOne->following()->attach($user->id);
        $followerTwo->following()->attach($user->id);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/users/{$user->id}/followers");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }
}
