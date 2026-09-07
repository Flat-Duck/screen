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
use Illuminate\Filesystem\FilesystemAdapter;
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

    /**
     * The security property that must survive the switch from streaming to redirecting: the
     * authorization checks still run on the app request, and only an authorized viewer ever sees
     * the object-store URL.
     */
    public function test_presigning_disk_redirects_instead_of_streaming_bytes_through_php(): void
    {
        Storage::fake('public');
        $this->presignFakeDisk('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/offloaded.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');

        $response = $this->get($url)->assertRedirect();
        $this->assertStringStartsWith('https://objects.test/posts/offloaded.jpg', (string) $response->headers->get('Location'));
        // Never "public": the Location header is a bearer capability for the object itself, and
        // it may be reused only for as long as the presigned URL it carries stays valid.
        $this->assertSame(
            'max-age='.config('social.media_offload_ttl_seconds').', private',
            $response->headers->get('Cache-Control'),
        );
    }

    public function test_offloaded_restricted_media_is_never_cacheable(): void
    {
        Storage::fake('local');
        $this->presignFakeDisk('local');
        $owner = User::factory()->create();
        $save = PrivateSave::factory()->for($owner)->create(['path' => 'saves/secret.jpg', 'source_disk' => 'local']);
        Storage::disk('local')->put($save->path, 'private-bytes');
        Sanctum::actingAs($owner);

        $url = $this->getJson('/api/v1/private-saves')->assertOk()->json('data.0.url');

        $this->get($url)->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_revoked_viewer_cannot_reach_the_object_store_url(): void
    {
        Storage::fake('public');
        $this->presignFakeDisk('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/revoked.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');
        $this->get($url)->assertRedirect();

        app(BlockService::class)->block($post->user, $viewer);

        // The redirect is minted per request, so losing access stops new ones being issued at all.
        $this->get($url)->assertNotFound();
    }

    public function test_offloading_can_be_disabled_without_changing_the_disk(): void
    {
        config()->set('social.media_offload_enabled', false);
        Storage::fake('public');
        $this->presignFakeDisk('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/streamed.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');

        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Storage::fake() builds a LocalFilesystemAdapter, which is deliberately excluded from
     * offloading even though it can sign URLs. Swapping in a non-local adapter over the same faked
     * storage reproduces what an R2 disk looks like to the delivery code — presigning, remote —
     * while keeping the files themselves readable by the rest of the test.
     */
    /**
     * The existence check used to run in the controller, before the branch. On an object store
     * that is a network HEAD on the critical path of every image, asking a question the presigned
     * URL answers for free — a missing object 404s at the store either way. Asserted by the
     * absence of the object: the redirect must still be issued.
     */
    public function test_the_offload_path_does_not_probe_the_object_store_for_existence(): void
    {
        Storage::fake('public');
        $this->presignFakeDisk('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        PostMedia::factory()->for($post)->create(['original_path' => 'posts/never-written.jpg']);
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');

        // Nothing was ever put on the disk, and it still redirects rather than 404ing.
        $this->get($url)->assertRedirect();
    }

    public function test_delivery_carries_a_strong_validator(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/tagged.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');

        $this->get($url)->assertOk()->assertHeader(
            'ETag',
            sprintf('"pm-%d-original-%d"', $media->id, $media->updated_at->getTimestamp()),
        );
    }

    /**
     * The 304 is answered before a presigned URL is minted, which is the point of having a
     * validator on a path that otherwise does work per request.
     */
    public function test_a_matching_if_none_match_is_answered_without_the_bytes(): void
    {
        Storage::fake('public');
        $this->presignFakeDisk('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/revalidated.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');
        $etag = sprintf('"pm-%d-original-%d"', $media->id, $media->updated_at->getTimestamp());

        $response = $this->get($url, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_a_stale_if_none_match_still_serves_the_media(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create();
        $post = Post::factory()->create();
        $media = PostMedia::factory()->for($post)->create(['original_path' => 'posts/stale.jpg']);
        Storage::disk('public')->put($media->original_path, 'post-bytes');
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->json('data.media.0.original_url');

        $this->get($url, ['If-None-Match' => '"pm-999-original-1"'])->assertOk();
    }

    private function presignFakeDisk(string $disk): void
    {
        $faked = Storage::disk($disk);

        Storage::set($disk, new class($faked->getDriver(), $faked->getAdapter(), $faked->getConfig()) extends FilesystemAdapter
        {
            public function providesTemporaryUrls(): bool
            {
                return true;
            }

            public function temporaryUrl($path, $expiration, array $options = []): string
            {
                return 'https://objects.test/'.$path.'?expires='.$expiration->getTimestamp();
            }
        });
    }
}
