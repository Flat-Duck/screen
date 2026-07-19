<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_conversation_succeeds(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/conversations', ['user_id' => $other->id]);

        $response->assertCreated();
        $response->assertJsonPath('data.other_participant.id', $other->id);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
    }

    public function test_starting_a_conversation_with_an_existing_thread_returns_the_same_one(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $first = $this->postJson('/api/v1/conversations', ['user_id' => $other->id]);
        $second = $this->postJson('/api/v1/conversations', ['user_id' => $other->id]);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_a_user_cannot_message_themselves(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/conversations', ['user_id' => $user->id]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_a_user_cannot_message_someone_blocked_either_way(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/users/{$other->id}/block")->assertNoContent();

        $response = $this->postJson('/api/v1/conversations', ['user_id' => $other->id]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_listing_conversations_returns_only_mine(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/conversations', ['user_id' => User::factory()->create()->id])->assertCreated();

        // A conversation between two other people should not appear.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/conversations', ['user_id' => User::factory()->create()->id])->assertCreated();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/conversations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_sending_a_message_succeeds_and_notifies_the_recipient(): void
    {
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])->json('data.id');

        $response = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Hello there']);

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Hello there');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
    }

    public function test_sending_a_message_updates_the_conversations_last_message_at(): void
    {
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])->json('data.id');

        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Hello there'])->assertCreated();

        $this->assertDatabaseMissing('conversations', ['id' => $conversationId, 'last_message_at' => null]);
    }

    public function test_a_non_participant_cannot_view_or_send_to_a_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Sanctum::actingAs($userA);
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $userB->id])->json('data.id');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'hi'])->assertForbidden();
        $this->patchJson("/api/v1/conversations/{$conversationId}/read")->assertForbidden();
    }

    public function test_listing_messages_defaults_to_newest_first_cursor_pagination(): void
    {
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])->json('data.id');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'first'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'second'])->assertCreated();

        $response = $this->getJson("/api/v1/conversations/{$conversationId}/messages");

        $response->assertOk();
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertSame(['second', 'first'], $response->json('data.*.body'));
    }

    public function test_polling_for_messages_after_an_id_returns_only_newer_ones_oldest_first(): void
    {
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $recipient->id])->json('data.id');
        $firstId = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'first'])->json('data.id');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'second'])->assertCreated();

        $response = $this->getJson("/api/v1/conversations/{$conversationId}/messages?after={$firstId}");

        $response->assertOk();
        $this->assertSame(['second'], $response->json('data.*.body'));
    }

    public function test_marking_a_conversation_read_clears_unread_for_the_reader(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Sanctum::actingAs($userA);
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $userB->id])->json('data.id');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'hi'])->assertCreated();

        Sanctum::actingAs($userB);
        $beforeRead = $this->getJson('/api/v1/conversations');
        $beforeRead->assertJsonPath('data.0.unread', true);

        $this->patchJson("/api/v1/conversations/{$conversationId}/read")->assertNoContent();

        $afterRead = $this->getJson('/api/v1/conversations');
        $afterRead->assertJsonPath('data.0.unread', false);
    }

    public function test_sending_after_a_block_mid_thread_is_forbidden_but_history_stays_visible(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Sanctum::actingAs($userA);
        $conversationId = $this->postJson('/api/v1/conversations', ['user_id' => $userB->id])->json('data.id');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'before block'])->assertCreated();

        $this->postJson("/api/v1/users/{$userB->id}/block")->assertNoContent();

        $sendResponse = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'after block']);
        $sendResponse->assertForbidden();

        $historyResponse = $this->getJson("/api/v1/conversations/{$conversationId}/messages");
        $historyResponse->assertOk();
        $historyResponse->assertJsonCount(1, 'data');
    }
}
