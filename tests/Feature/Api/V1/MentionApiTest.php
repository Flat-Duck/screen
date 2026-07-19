<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MentionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function createPost(User $user, ?string $caption): int
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/posts', [
            'caption' => $caption,
            'images' => [UploadedFile::fake()->image('shot.jpg', 400, 800)],
        ]);

        return $response->json('data.id');
    }

    public function test_creating_a_post_mentioning_a_user_creates_a_mention_and_notifies_them(): void
    {
        $mentioned = User::factory()->create(['username' => 'alice']);
        $author = User::factory()->create();

        $postId = $this->createPost($author, 'Great shot @alice!');

        $this->assertDatabaseHas('mentions', [
            'mentionable_type' => Post::class,
            'mentionable_id' => $postId,
            'mentioned_user_id' => $mentioned->id,
            'mentioner_id' => $author->id,
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $mentioned->id]);
    }

    public function test_mentioning_yourself_does_not_create_a_mention(): void
    {
        $author = User::factory()->create(['username' => 'author']);

        $postId = $this->createPost($author, 'Look at my own post @author');

        $this->assertDatabaseCount('mentions', 0);
    }

    public function test_mentioning_a_nonexistent_username_is_silently_ignored(): void
    {
        $author = User::factory()->create();

        $this->createPost($author, 'Hey @nobodyhere check this out');

        $this->assertDatabaseCount('mentions', 0);
    }

    public function test_editing_a_captions_mentions_removes_dropped_ones_without_re_notifying_kept_ones(): void
    {
        $alice = User::factory()->create(['username' => 'alice']);
        $bob = User::factory()->create(['username' => 'bob']);
        $author = User::factory()->create();

        $postId = $this->createPost($author, 'Hi @alice and @bob');
        $this->assertDatabaseCount('mentions', 2);
        $this->assertDatabaseCount('notifications', 2);

        $this->patchJson("/api/v1/posts/{$postId}", ['caption' => 'Hi @alice only now'])->assertOk();

        $this->assertDatabaseCount('mentions', 1);
        $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $alice->id, 'mentionable_id' => $postId]);
        $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $bob->id, 'mentionable_id' => $postId]);
        // Alice was already mentioned before the edit — no second notification for her.
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_editing_a_captions_mentions_notifies_newly_added_ones(): void
    {
        $alice = User::factory()->create(['username' => 'alice']);
        $carol = User::factory()->create(['username' => 'carol']);
        $author = User::factory()->create();

        $postId = $this->createPost($author, 'Hi @alice');
        $this->assertDatabaseCount('notifications', 1);

        $this->patchJson("/api/v1/posts/{$postId}", ['caption' => 'Hi @alice and @carol'])->assertOk();

        $this->assertDatabaseCount('mentions', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $carol->id]);
    }

    public function test_commenting_with_a_mention_creates_a_mention_and_notifies_them(): void
    {
        $mentioned = User::factory()->create(['username' => 'alice']);
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'cc @alice']);
        $response->assertCreated();

        $this->assertDatabaseHas('mentions', [
            'mentionable_type' => Comment::class,
            'mentionable_id' => $response->json('data.id'),
            'mentioned_user_id' => $mentioned->id,
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $mentioned->id]);
    }

    public function test_a_blocked_users_mention_does_not_notify(): void
    {
        $mentioned = User::factory()->create(['username' => 'alice']);
        $author = User::factory()->create();
        Sanctum::actingAs($mentioned);
        $this->postJson("/api/v1/users/{$author->id}/block")->assertNoContent();

        $this->createPost($author, 'Hi @alice');

        // The Mention row still gets created (text isn't suppressed, only the notification).
        $this->assertDatabaseCount('mentions', 1);
        $this->assertDatabaseCount('notifications', 0);
    }
}
