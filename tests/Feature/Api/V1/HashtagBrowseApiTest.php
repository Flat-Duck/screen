<?php

namespace Tests\Feature\Api\V1;

use App\Models\Hashtag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HashtagBrowseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function createPostWithCaption(User $user, string $caption): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/posts', [
            'caption' => $caption,
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)],
        ])->assertCreated();
    }

    public function test_viewing_a_hashtag_returns_its_post_count(): void
    {
        $this->createPostWithCaption(User::factory()->create(), 'Found a #bug today');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/hashtags/bug');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'bug');
        $response->assertJsonPath('data.posts_count', 1);
    }

    public function test_hashtag_lookup_is_case_insensitive(): void
    {
        $this->createPostWithCaption(User::factory()->create(), 'Found a #bug today');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/hashtags/BUG');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'bug');
    }

    public function test_viewing_an_unknown_hashtag_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/hashtags/nonexistent');

        $response->assertNotFound();
    }

    public function test_listing_posts_for_a_hashtag(): void
    {
        $author = User::factory()->create();
        $this->createPostWithCaption($author, 'Found a #bug today');
        $this->createPostWithCaption($author, 'No tag here');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/hashtags/bug/posts');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_hashtag_posts_exclude_blocked_authors(): void
    {
        $blockedAuthor = User::factory()->create();
        $this->createPostWithCaption($blockedAuthor, 'Found a #bug today');
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/users/{$blockedAuthor->id}/block")->assertNoContent();

        $response = $this->getJson('/api/v1/hashtags/bug/posts');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_following_a_hashtag_succeeds(): void
    {
        $hashtag = Hashtag::factory()->create(['name' => 'bug']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/hashtags/bug/follow');

        $response->assertNoContent();
        $this->assertDatabaseHas('hashtag_user', ['hashtag_id' => $hashtag->id]);
    }

    public function test_following_an_already_followed_hashtag_is_idempotent(): void
    {
        Hashtag::factory()->create(['name' => 'bug']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/hashtags/bug/follow')->assertNoContent();
        $response = $this->postJson('/api/v1/hashtags/bug/follow');

        $response->assertNoContent();
        $this->assertDatabaseCount('hashtag_user', 1);
    }

    public function test_unfollowing_a_hashtag_removes_it(): void
    {
        Hashtag::factory()->create(['name' => 'bug']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/hashtags/bug/follow')->assertNoContent();
        $response = $this->deleteJson('/api/v1/hashtags/bug/follow');

        $response->assertNoContent();
        $this->assertDatabaseCount('hashtag_user', 0);
    }

    public function test_viewing_a_hashtag_reflects_is_followed_for_the_viewer(): void
    {
        Hashtag::factory()->create(['name' => 'bug']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/hashtags/bug/follow')->assertNoContent();
        $response = $this->getJson('/api/v1/hashtags/bug');

        $response->assertOk();
        $response->assertJsonPath('data.is_followed', true);
    }

    public function test_listing_followed_hashtags_returns_only_mine(): void
    {
        // Deliberately not named "followed" — that name would collide with the
        // GET /hashtags/followed route itself, since it's registered ahead of the
        // {hashtag} wildcard.
        Hashtag::factory()->create(['name' => 'alpha']);
        Hashtag::factory()->create(['name' => 'beta']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/hashtags/alpha/follow')->assertNoContent();

        $response = $this->getJson('/api/v1/hashtags/followed');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'alpha');
    }
}
