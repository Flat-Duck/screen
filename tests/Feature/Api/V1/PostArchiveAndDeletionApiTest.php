<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Accounts\RestoreDeletedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostArchiveAndDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_is_private_idempotent_and_reversible(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $viewer->following()->attach($owner);
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/posts/{$post->id}/archive")->assertNoContent();
        $this->postJson("/api/v1/posts/{$post->id}/archive")->assertNoContent();
        $this->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
        $this->getJson('/api/v1/archived-posts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.archived_at', fn ($value): bool => is_string($value));

        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/archived-posts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/feed/following')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/posts/{$post->id}/archive")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/posts/{$post->id}/archive")->assertNoContent();
        $this->deleteJson("/api/v1/posts/{$post->id}/archive")->assertNoContent();
        $this->getJson("/api/v1/posts/{$post->id}")->assertOk();
    }

    public function test_an_archived_post_can_be_moved_directly_to_recently_deleted(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/posts/{$post->id}/archive")->assertNoContent();
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertNoContent();

        $this->getJson('/api/v1/archived-posts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/recently-deleted-posts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.archived_at', null);
    }

    public function test_recently_deleted_is_owner_only_and_restore_returns_the_post(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertNoContent();

        $this->getJson('/api/v1/recently-deleted-posts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.deleted_at', fn ($value): bool => is_string($value))
            ->assertJsonPath('data.0.scheduled_purge_at', fn ($value): bool => is_string($value));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/recently-deleted-posts')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/posts/{$post->id}/restore")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/posts/{$post->id}/restore")->assertOk()->assertJsonPath('data.id', $post->id);
        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_expired_or_cleanup_started_posts_cannot_be_restored(): void
    {
        $owner = User::factory()->create();
        $expired = Post::factory()->for($owner)->create();
        $expired->delete();
        $expired->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        $failed = Post::factory()->for($owner)->create();
        $failed->delete();
        $failed->forceFill(['purge_status' => 'failed'])->saveQuietly();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/posts/{$expired->id}/restore")->assertStatus(410);
        $this->postJson("/api/v1/posts/{$failed->id}/restore")->assertConflict();
    }

    public function test_permanent_delete_requires_step_up_and_removes_media_and_rows(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        PostMedia::factory()->for($post)->create(['original_path' => 'posts/delete-me.jpg', 'thumbnail_path' => 'posts/delete-me-thumb.webp']);
        Storage::disk('public')->put('posts/delete-me.jpg', 'original');
        Storage::disk('public')->put('posts/delete-me-thumb.webp', 'thumbnail');
        $post->delete();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/posts/{$post->id}/permanently-delete", ['current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->deleteJson("/api/v1/posts/{$post->id}/permanently-delete", ['current_password' => 'password'])
            ->assertNoContent();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        Storage::disk('public')->assertMissing('posts/delete-me.jpg');
        Storage::disk('public')->assertMissing('posts/delete-me-thumb.webp');
    }

    public function test_account_lifecycle_includes_archived_posts_and_preserves_archive_state(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        $post->forceFill(['archived_at' => now()])->save();

        app(AccountService::class)->deleteAccount($owner);
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        app(RestoreDeletedAccount::class)($owner->id);

        $restored = Post::withoutGlobalScope(NotArchivedScope::class)->findOrFail($post->id);
        $this->assertNotNull($restored->archived_at);
        $this->assertNull($restored->deleted_at);
    }
}
