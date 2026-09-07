<?php

namespace Tests\Feature\Media;

use App\Actions\Media\SyncPublicMedia;
use App\Enums\AccountVisibility;
use App\Jobs\PublishPostMediaToCdn;
use App\Jobs\UnpublishPostMediaFromCdn;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\PostLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The hybrid CDN serves *public* post media from a public bucket with no per-request check. What
 * these pin is the boundary: nothing private ever gets copied there, and everything that stops
 * being public gets pulled back out.
 */
class PublicCdnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('r2_public');
        config([
            'social.public_cdn.enabled' => true,
            'social.public_cdn.disk' => 'r2_public',
            'filesystems.disks.r2_public.url' => 'https://cdn.akukas.test',
        ]);
    }

    public function test_a_published_media_item_is_served_from_the_cdn(): void
    {
        $media = $this->publishedMedia();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);

        $url = $this->getJson("/api/v1/posts/{$media->post_id}")
            ->assertOk()
            ->json('data.media.0.original_url');

        $this->assertStringStartsWith('https://cdn.akukas.test/', $url);
        $this->assertStringContainsString($media->public_token, $url);
    }

    /** Turning the flag off is the rollback: every newly minted URL reverts with no deploy. */
    public function test_disabling_the_feature_reverts_to_the_signed_route(): void
    {
        $media = $this->publishedMedia();
        config(['social.public_cdn.enabled' => false]);
        Sanctum::actingAs(User::factory()->create());

        $url = $this->getJson("/api/v1/posts/{$media->post_id}")
            ->assertOk()
            ->json('data.media.0.original_url');

        $this->assertStringNotContainsString('cdn.akukas.test', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    /**
     * The guard that makes optimistic dispatch safe: a post can be archived, deleted or its author
     * made private between the job being queued and it running.
     */
    public function test_the_publish_job_refuses_a_post_that_is_no_longer_public(): void
    {
        $media = $this->readyMedia();
        $media->post->user->forceFill(['account_visibility' => AccountVisibility::Private])->save();

        (new PublishPostMediaToCdn($media->id))->handle();

        $this->assertNull($media->fresh()->public_path);
        Storage::disk('r2_public')->assertDirectoryEmpty('');
    }

    public function test_the_publish_job_does_nothing_while_the_feature_is_off(): void
    {
        config(['social.public_cdn.enabled' => false]);
        $media = $this->readyMedia();

        (new PublishPostMediaToCdn($media->id))->handle();

        $this->assertNull($media->fresh()->public_path);
    }

    public function test_unpublishing_deletes_the_objects_and_rotates_the_token(): void
    {
        $media = $this->publishedMedia();
        $token = $media->public_token;
        Storage::disk('r2_public')->assertExists($media->public_path);

        (new UnpublishPostMediaFromCdn($media->id))->handle();

        $fresh = $media->fresh();
        $this->assertNull($fresh->public_path);
        $this->assertNull($fresh->public_thumbnail_path);
        // Rotated, not merely blanked: every URL ever handed out is permanently dead.
        $this->assertNull($fresh->public_token);
        Storage::disk('r2_public')->assertMissing("{$token}/o.jpg");
    }

    public function test_archiving_a_post_pulls_its_public_copies(): void
    {
        Queue::fake();
        $media = $this->publishedMedia();
        $owner = $media->post->user;

        app(PostLibraryService::class)->archive($owner, $media->post_id);

        Queue::assertPushed(
            UnpublishPostMediaFromCdn::class,
            fn (UnpublishPostMediaFromCdn $job): bool => $job->postMediaId === $media->id,
        );
    }

    public function test_making_an_account_private_pulls_every_public_copy(): void
    {
        Queue::fake();
        $media = $this->publishedMedia();
        $owner = $media->post->user;
        $owner->forceFill(['account_visibility' => AccountVisibility::Private])->save();

        app(SyncPublicMedia::class)->forUser($owner->fresh());

        Queue::assertPushed(UnpublishPostMediaFromCdn::class);
    }

    /** The net under every transition site a future change might forget. */
    public function test_the_reconciler_finds_and_corrects_a_published_private_post(): void
    {
        Queue::fake();
        $media = $this->publishedMedia();
        // Straight to the database, bypassing every wire-up, as a forgotten trigger would leave it.
        $media->post->user->forceFill(['account_visibility' => AccountVisibility::Private])->save();

        $this->artisan('media:reconcile-public', ['--fix' => true])
            ->expectsOutputToContain('1 wrongly published')
            ->assertSuccessful();

        Queue::assertPushed(UnpublishPostMediaFromCdn::class);
    }

    public function test_the_reconciler_reports_without_fixing_and_fails_when_something_is_exposed(): void
    {
        Queue::fake();
        $media = $this->publishedMedia();
        $media->post->user->forceFill(['account_visibility' => AccountVisibility::Private])->save();

        $this->artisan('media:reconcile-public')->assertFailed();

        Queue::assertNotPushed(UnpublishPostMediaFromCdn::class);
    }

    public function test_the_reconciler_is_a_no_op_while_the_feature_is_off(): void
    {
        config(['social.public_cdn.enabled' => false]);

        $this->artisan('media:reconcile-public', ['--fix' => true])
            ->expectsOutputToContain('nothing to reconcile')
            ->assertSuccessful();
    }

    /** Media whose thumbnail has not been generated yet, on a public post. */
    private function readyMedia(): PostMedia
    {
        $author = User::factory()->create(['account_visibility' => AccountVisibility::Public]);
        $post = Post::factory()->for($author)->create();
        $media = PostMedia::factory()->for($post)->create([
            'original_path' => 'posts/cdn.jpg',
            'thumbnail_path' => 'posts/cdn-thumb.webp',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('public')->put($media->original_path, 'original-bytes');
        Storage::disk('public')->put($media->thumbnail_path, 'thumb-bytes');

        return $media;
    }

    private function publishedMedia(): PostMedia
    {
        $media = $this->readyMedia();
        (new PublishPostMediaToCdn($media->id))->handle();

        return $media->fresh()->load('post.user');
    }
}
