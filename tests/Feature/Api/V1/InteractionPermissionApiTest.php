<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InteractionPermissionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_return_and_validate_interaction_controls(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.interactions.comments_from', 'everyone')
            ->assertJsonPath('data.interactions.messages_from', 'everyone')
            ->assertJsonPath('data.interactions.reposts_allowed', true);

        $this->patchJson('/api/v1/settings', [
            'interactions' => [
                'comments_from' => 'mutuals',
                'mentions_from' => 'no_one',
                'messages_from' => 'followers',
                'reposts_from' => 'following',
                'reposts_allowed' => false,
            ],
        ])->assertOk()->assertJsonPath('data.interactions.comments_from', 'mutuals');

        $this->patchJson('/api/v1/settings', [
            'interactions' => ['comments_from' => 'close_friends'],
        ])->assertUnprocessable()->assertJsonValidationErrors('interactions.comments_from');
    }

    public function test_comment_audience_supports_followers_following_mutuals_and_no_one(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($actor);

        $this->setInteractions($owner, ['comments_from' => 'followers']);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'No'])->assertForbidden();
        $actor->following()->attach($owner->id);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Follower'])->assertCreated();

        $this->setInteractions($owner, ['comments_from' => 'following']);
        $owner->following()->attach($actor->id);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Following'])->assertCreated();

        $this->setInteractions($owner, ['comments_from' => 'mutuals']);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Mutual'])->assertCreated();
        $actor->following()->detach($owner->id);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Not mutual'])->assertForbidden();

        $this->setInteractions($owner, ['comments_from' => 'no_one']);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'No one'])->assertForbidden();
    }

    public function test_post_owner_can_disable_new_comments_without_hiding_existing_comments(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Existing'])->assertCreated();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/posts/{$post->id}", ['comments_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.comments_enabled', false);

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Blocked'])->assertForbidden();
        $this->getJson("/api/v1/posts/{$post->id}/comments")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reposts_respect_account_and_post_controls(): void
    {
        $owner = User::factory()->create();
        $this->setInteractions($owner, ['reposts_from' => 'followers', 'reposts_allowed' => true]);
        $actor = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertForbidden();
        $actor->following()->attach($owner->id);
        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();

        $this->deleteJson("/api/v1/posts/{$post->id}/repost")->assertNoContent();
        $this->setInteractions($owner, ['reposts_allowed' => false]);
        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertForbidden();

        $post->update(['reposts_enabled' => false]);
        $this->setInteractions($owner, ['reposts_allowed' => true]);
        $this->postJson("/api/v1/posts/{$post->id}/repost")->assertForbidden();
    }

    public function test_disallowed_mentions_are_not_persisted_or_notified(): void
    {
        $mentioned = User::factory()->create(['username' => 'alice']);
        $this->setInteractions($mentioned, ['mentions_from' => 'no_one']);
        $author = User::factory()->create();
        $post = Post::factory()->for($author)->create();
        Sanctum::actingAs($author);

        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Hello @alice'])->assertCreated();

        $this->assertDatabaseCount('mentions', 0);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $mentioned->id]);
    }

    public function test_message_permission_routes_disallowed_new_contacts_to_requests(): void
    {
        $recipient = User::factory()->create();
        $this->setInteractions($recipient, ['messages_from' => 'followers']);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('initial_message');

        $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id, 'initial_message' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.state', 'requested');
    }

    public function test_private_account_and_block_rules_override_permissive_settings(): void
    {
        $owner = User::factory()->create(['account_visibility' => 'private']);
        $actor = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Hidden'])->assertNotFound();

        $owner->account_visibility = 'public';
        $owner->save();
        $this->postJson("/api/v1/users/{$owner->id}/block")->assertNoContent();
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Blocked'])->assertForbidden();
    }

    /** @param array<string, bool|string> $settings */
    private function setInteractions(User $user, array $settings): void
    {
        $user->settings = ['interactions' => $settings];
        $user->save();
    }
}
