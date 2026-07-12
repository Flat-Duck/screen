<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_a_public_profile_returns_post_and_follow_counts(): void
    {
        $target = User::factory()->create();
        Post::factory()->count(2)->create(['user_id' => $target->id]);

        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $response->assertJsonPath('data.posts_count', 2);
        $response->assertJsonPath('data.followers_count', 0);
        $response->assertJsonPath('data.following_count', 0);
    }

    public function test_user_resource_returns_the_full_expected_shape(): void
    {
        $target = User::factory()->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'username', 'name', 'bio', 'avatar_url',
                'posts_count', 'followers_count', 'following_count',
                'is_following', 'created_at',
            ],
        ]);
    }

    public function test_a_users_posts_are_returned_newest_first_and_cursor_paginated(): void
    {
        $target = User::factory()->create();
        $older = Post::factory()->create(['user_id' => $target->id, 'created_at' => now()->subMinutes(2)]);
        $newer = Post::factory()->create(['user_id' => $target->id, 'created_at' => now()->subMinute()]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/v1/users/{$target->id}/posts");

        $response->assertOk();
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_updating_own_bio_and_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', [
            'bio' => 'Screenshots enthusiast',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.bio', 'Screenshots enthusiast');
        $this->assertNotNull($user->fresh()->avatar_path);
    }

    public function test_updating_avatar_replaces_and_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 300, 300),
        ])->assertOk();
        $firstAvatarPath = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($firstAvatarPath);

        $this->patchJson('/api/v1/profile', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 300, 300),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstAvatarPath);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }

    public function test_updating_own_birth_date_and_country_code(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', [
            'birth_date' => '1990-05-15',
            'country_code' => 'eg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.birth_date', '1990-05-15');
        $response->assertJsonPath('data.country_code', 'EG');
        $this->assertSame('EG', $user->fresh()->country_code);
    }

    public function test_birth_date_is_hidden_on_another_users_public_profile(): void
    {
        $target = User::factory()->create(['birth_date' => '1990-05-15']);
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('birth_date', $response->json('data'));
    }

    public function test_country_code_is_visible_on_another_users_public_profile(): void
    {
        $target = User::factory()->create(['country_code' => 'EG']);
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $response->assertJsonPath('data.country_code', 'EG');
    }

    public function test_birth_date_must_be_in_the_past(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', ['birth_date' => now()->addDay()->toDateString()]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['birth_date']);
    }

    public function test_country_code_must_be_two_letters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/profile', ['country_code' => 'EGY']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['country_code']);
    }
}
