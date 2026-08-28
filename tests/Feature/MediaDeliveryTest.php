<?php

namespace Tests\Feature;

use App\Enums\AccountVisibility;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PrivateSave;
use App\Models\User;
use App\Services\BlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_resource_returns_a_signed_capability_instead_of_a_storage_path(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/private-name.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->json('data.media.0.original_url');

        $this->assertStringContainsString("/media/posts/{$media->id}/original", $url);
        $this->assertStringNotContainsString($media->original_path, $url);
        $mediaResponse = $this->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('public', (string) $mediaResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $mediaResponse->headers->get('Cache-Control'));
    }

    public function test_tampered_and_expired_post_media_urls_are_rejected(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create();
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        $url = $media->originalUrl($viewer);

        $this->get($url.'&viewer=999999')->assertForbidden();

        $this->travel((int) config('social.media_url_ttl_seconds') + 1)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_current_block_privacy_and_archive_state_revoke_a_previously_issued_url(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        $media = PostMedia::factory()->for($post)->create();
        Storage::disk('public')->put($media->original_path, 'post-bytes');

        $blockedUrl = $media->originalUrl($viewer);
        app(BlockService::class)->block($viewer, $author);
        $this->get($blockedUrl)->assertNotFound();

        app(BlockService::class)->unblock($viewer, $author);
        $privacyUrl = $media->originalUrl($viewer);
        $author->forceFill(['account_visibility' => AccountVisibility::Private])->save();
        $this->get($privacyUrl)->assertNotFound();

        $author->forceFill(['account_visibility' => AccountVisibility::Public])->save();
        $archiveUrl = $media->originalUrl($viewer);
        $post->forceFill(['archived_at' => now()])->save();
        $this->get($archiveUrl)->assertNotFound();
    }

    public function test_owner_can_fetch_archived_media_but_it_is_not_cacheable(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->create(['archived_at' => now()]);
        $media = PostMedia::factory()->for($post)->create();
        Storage::disk('public')->put($media->original_path, 'post-bytes');

        $this->get($media->originalUrl($owner))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_private_save_url_is_owner_bound_private_and_expires(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $save = PrivateSave::factory()->for($owner)->create([
            'path' => 'private-saves/owner/shot.jpg',
            'source_disk' => 'local',
        ]);
        Storage::disk('local')->put($save->path, 'private-bytes');
        $url = $save->url($owner);

        $this->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $other = User::factory()->create();
        $this->get(str_replace('viewer='.$owner->id, 'viewer='.$other->id, $url))->assertForbidden();

        $this->travel((int) config('social.media_url_ttl_seconds') + 1)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_avatar_url_is_signed_and_a_block_revokes_it(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $profile = User::factory()->create(['avatar_path' => 'avatars/profile.jpg']);
        Storage::disk('public')->put($profile->avatar_path, 'avatar-bytes');
        $url = $profile->avatarUrl($viewer);

        $this->assertStringNotContainsString($profile->avatar_path, $url);
        $this->get($url)->assertOk();

        app(BlockService::class)->block($viewer, $profile);
        $this->get($url)->assertNotFound();
    }

    public function test_private_group_photo_requires_current_membership(): void
    {
        Storage::fake('public');
        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $group = Group::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Private group',
            'visibility' => 'private',
            'photo_path' => 'groups/private.jpg',
        ]);
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);
        Storage::disk('public')->put($group->photo_path, 'group-bytes');
        $url = $group->photoUrl($viewer);

        $this->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        GroupMember::query()->where('group_id', $group->id)->where('user_id', $viewer->id)->delete();
        $this->get($url)->assertNotFound();
    }
}
