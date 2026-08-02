<?php

namespace Tests\Feature\Api\V1;

use App\Models\Hashtag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    public function test_trending_hashtags_are_ranked_by_recent_post_count(): void
    {
        $author = User::factory()->create();
        $this->createPostWithCaption($author, '#popular one');
        $this->createPostWithCaption($author, '#popular two');
        $this->createPostWithCaption($author, '#popular three');
        $this->createPostWithCaption($author, '#quiet one');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/hashtags/trending')->assertOk();

        $response->assertJsonPath('data.0.name', 'popular');
        $response->assertJsonPath('data.0.posts_count', 3);
        $response->assertJsonPath('data.1.name', 'quiet');
    }

    public function test_trending_hashtags_excludes_activity_outside_the_window(): void
    {
        $author = User::factory()->create();
        $this->createPostWithCaption($author, '#stale news');
        $hashtag = Hashtag::query()->where('name', 'stale')->firstOrFail();
        DB::table('hashtag_post')->where('hashtag_id', $hashtag->id)->update(['created_at' => now()->subDays(30)]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/hashtags/trending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/hashtags/trending?days=90')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_trending_hashtags_respects_the_limit_parameter(): void
    {
        $author = User::factory()->create();
        $this->createPostWithCaption($author, '#one');
        $this->createPostWithCaption($author, '#two');
        $this->createPostWithCaption($author, '#three');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/hashtags/trending?limit=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_trending_hashtags_reflects_is_followed_for_the_viewer(): void
    {
        $author = User::factory()->create();
        $this->createPostWithCaption($author, '#bug today');
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/hashtags/bug/follow')->assertNoContent();

        $this->getJson('/api/v1/hashtags/trending')->assertOk()->assertJsonPath('data.0.is_followed', true);
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
