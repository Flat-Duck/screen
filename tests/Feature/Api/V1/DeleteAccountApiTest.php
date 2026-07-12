<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteAccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $token = $user->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'wrong']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
        $this->assertNotSoftDeleted($user);
    }

    public function test_deleting_does_not_require_a_password_for_a_social_only_account(): void
    {
        $user = User::factory()->create(['password' => null]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account')
            ->assertNoContent();

        $this->assertSoftDeleted($user);
    }

    public function test_deleting_soft_deletes_the_account_revokes_all_tokens_and_hides_it_from_others(): void
    {
        $user = User::factory()->create(['password' => 'password123!']);
        $token = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $post = Post::factory()->for($user)->create();

        $other = User::factory()->create();
        $other->following()->attach($user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->assertSoftDeleted($user);
        $this->assertSoftDeleted($post);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $otherToken = $other->createToken('mobile')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/v1/users/{$user->id}")
            ->assertNotFound();

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/v1/users/{$other->id}/following")
            ->assertJsonCount(0, 'data');
    }

    public function test_a_deleted_account_cannot_log_back_in(): void
    {
        $user = User::factory()->create(['password' => 'password123!', 'username' => 'deleteme']);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/account', ['current_password' => 'password123!'])
            ->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['login' => 'deleteme', 'password' => 'password123!'])
            ->assertUnprocessable();
    }

    public function test_prune_command_purges_accounts_and_their_files_past_the_retention_window(): void
    {
        Storage::fake(config('social.media_disk'));

        $user = User::factory()->create(['avatar_path' => 'avatars/1/avatar.jpg']);
        Storage::disk(config('social.media_disk'))->put('avatars/1/avatar.jpg', 'fake-avatar');

        $post = Post::factory()->for($user)->create();
        $post->media()->create([
            'position' => 0,
            'original_path' => "posts/{$post->id}/original.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk(config('social.media_disk'))->put("posts/{$post->id}/original.jpg", 'fake-image');

        $user->posts()->delete();
        $user->delete();
        $user->deleted_at = now()->subDays(31);
        $user->saveQuietly();

        Artisan::call('users:prune-deleted');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        Storage::disk(config('social.media_disk'))->assertMissing('avatars/1/avatar.jpg');
        Storage::disk(config('social.media_disk'))->assertMissing("posts/{$post->id}/original.jpg");
    }

    public function test_prune_command_leaves_accounts_within_the_retention_window_alone(): void
    {
        $user = User::factory()->create();
        $user->delete();

        Artisan::call('users:prune-deleted');

        $this->assertSoftDeleted($user);
    }

    public function test_restore_command_restores_the_account_and_its_posts(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $user->posts()->delete();
        $user->delete();

        Artisan::call('users:restore', ['id' => $user->id]);

        $this->assertNotSoftDeleted($user);
        $this->assertNotSoftDeleted($post);
    }
}
