<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_contact_creates_a_request_with_one_bounded_initial_message(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/v1/conversations', [
            'user_id' => $recipient->id,
            'initial_message' => 'Could we talk?',
        ])->assertCreated()->assertJsonPath('data.state', 'requested');

        $conversationId = $response->json('data.id');
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'state' => ConversationState::Requested->value,
            'requested_by' => $sender->id,
        ]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationId, 'sender_id' => $sender->id, 'body' => 'Could we talk?']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Second message'])->assertStatus(409);
    }

    public function test_recipient_sees_request_in_separate_inbox_and_can_accept_it(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/message-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.latest_message.body', 'Hello request');

        $this->postJson("/api/v1/conversations/{$conversation->id}/accept")->assertNoContent();
        $this->assertSame(ConversationState::Active, $conversation->fresh()->state);
        $this->assertNotNull($conversation->fresh()->accepted_at);

        Sanctum::actingAs($sender);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Thanks'])
            ->assertCreated();
    }

    public function test_only_recipient_can_accept_or_reject_request(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);

        Sanctum::actingAs($sender);
        $this->postJson("/api/v1/conversations/{$conversation->id}/accept")->assertNotFound();
        $this->postJson("/api/v1/conversations/{$conversation->id}/reject")->assertNotFound();
    }

    public function test_rejection_enforces_cooldown_and_does_not_enter_primary_inbox(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);

        Sanctum::actingAs($recipient);
        $this->postJson("/api/v1/conversations/{$conversation->id}/reject")->assertNoContent();

        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/conversations', [
            'user_id' => $recipient->id,
            'initial_message' => 'Try again',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_requested_conversation_does_not_expose_read_receipts(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);
        Sanctum::actingAs($recipient);

        $this->patchJson("/api/v1/conversations/{$conversation->id}/read")->assertStatus(409);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'last_read_at' => null,
        ]);
    }

    public function test_conversation_can_be_hidden_locally_and_reported(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);
        Sanctum::actingAs($recipient);

        $this->postJson("/api/v1/conversations/{$conversation->id}/report", [
            'reason' => 'harassment',
            'details' => 'Unwanted request',
        ])->assertCreated();
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $recipient->id,
            'reportable_type' => Conversation::class,
            'reportable_id' => $conversation->id,
        ]);

        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertNoContent();
        $this->getJson('/api/v1/message-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_blocking_rejects_the_request_and_hides_it_from_the_blocker(): void
    {
        [$sender, $recipient] = $this->usersRequiringRequests();
        $conversation = $this->createRequest($sender, $recipient);
        Sanctum::actingAs($recipient);

        $this->postJson("/api/v1/users/{$sender->id}/block")->assertNoContent();

        $this->assertSame(ConversationState::Rejected, $conversation->fresh()->state);
        $this->getJson('/api/v1/message-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_no_one_setting_rejects_requests_entirely(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $recipient->settings = ['interactions' => ['messages_from' => 'no_one']];
        $recipient->save();
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/conversations', [
            'user_id' => $recipient->id,
            'initial_message' => 'Hello',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('conversations', 0);
    }

    /** @return array{User, User} */
    private function usersRequiringRequests(): array
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $recipient->settings = ['interactions' => ['messages_from' => 'followers']];
        $recipient->save();

        return [$sender, $recipient];
    }

    private function createRequest(User $sender, User $recipient): Conversation
    {
        Sanctum::actingAs($sender);
        $id = $this->postJson('/api/v1/conversations', [
            'user_id' => $recipient->id,
            'initial_message' => 'Hello request',
        ])->assertCreated()->json('data.id');

        return Conversation::query()->findOrFail($id);
    }
}
