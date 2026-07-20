<?php

namespace Tests\Feature\Api\V1;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HiddenWordsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_terms_are_normalized_deduplicated_and_encrypted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/hidden-terms', ['value' => 'Café', 'type' => 'word'])
            ->assertCreated()
            ->assertJsonPath('data.value', 'Café');
        $this->postJson('/api/v1/hidden-terms', ['value' => "CAFE\u{0301}", 'type' => 'word'])->assertOk();

        $this->assertDatabaseCount('user_hidden_terms', 1);
        $stored = DB::table('user_hidden_terms')->where('id', $first->json('data.id'))->first();
        $this->assertNotSame('Café', $stored->original_value);
        $this->assertSame('café', $stored->normalized_value);

        $this->getJson('/api/v1/hidden-terms')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/hidden-terms/'.$first->json('data.id'))->assertNoContent();
        $this->assertDatabaseCount('user_hidden_terms', 0);
    }

    public function test_common_evasion_is_matched_and_comment_is_redacted_only_for_owner(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs($owner);
        $termId = $this->postJson('/api/v1/hidden-terms', ['value' => 'toxic'])->assertCreated()->json('data.id');

        Sanctum::actingAs($author);
        $commentId = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'This is t0.x-i_c'])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('content_filter_matches', ['user_id' => $owner->id, 'filterable_id' => $commentId, 'reason' => 'hidden_term']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $owner->id]);
        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertJsonPath('data.0.body', 'This is t0.x-i_c')
            ->assertJsonPath('data.0.is_filtered', false);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertJsonPath('data.0.body', null)
            ->assertJsonPath('data.0.is_filtered', true);

        $this->deleteJson("/api/v1/hidden-terms/{$termId}")->assertNoContent();
        $this->getJson("/api/v1/posts/{$post->id}/comments")
            ->assertJsonPath('data.0.body', 'This is t0.x-i_c')
            ->assertJsonPath('data.0.is_filtered', false);
    }

    public function test_message_filter_is_recipient_local_and_retains_original_for_moderation(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($recipient);
        $this->postJson('/api/v1/hidden-terms', ['value' => 'secret phrase', 'type' => 'phrase'])->assertCreated();

        Sanctum::actingAs($sender);
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])->assertCreated()->json('data.id');
        $messageId = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'A SECRET---PHRASE here'])
            ->assertCreated()
            ->json('data.id');

        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertJsonPath('data.0.body', 'A SECRET---PHRASE here')
            ->assertJsonPath('data.0.is_filtered', false);

        Sanctum::actingAs($recipient);
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertJsonPath('data.0.body', null)
            ->assertJsonPath('data.0.is_filtered', true);

        $this->assertDatabaseHas('messages', ['id' => $messageId, 'body' => 'A SECRET---PHRASE here']);
        $this->assertDatabaseHas('content_filter_matches', ['user_id' => $recipient->id, 'filterable_id' => $messageId]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $recipient->id]);
    }

    public function test_configured_offensive_filter_marks_content_when_enabled(): void
    {
        config(['social.offensive_terms' => ['policy blocked phrase']]);
        $owner = User::factory()->create();
        $owner->settings = ['content_filters' => ['hide_offensive_comments' => true]];
        $owner->save();
        $post = Post::factory()->for($owner)->create();
        Sanctum::actingAs(User::factory()->create());

        $commentId = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'POLICY blocked phrase'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('content_filter_matches', [
            'user_id' => $owner->id,
            'filterable_id' => $commentId,
            'reason' => 'offensive',
        ]);
    }

    public function test_users_cannot_delete_another_users_term(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $termId = $this->postJson('/api/v1/hidden-terms', ['value' => 'private'])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/v1/hidden-terms/{$termId}")->assertNotFound();
        $this->assertDatabaseHas('user_hidden_terms', ['id' => $termId]);
    }
}
