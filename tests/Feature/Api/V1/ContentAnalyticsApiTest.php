<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContentEvent;
use App\Models\Device;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_uses_server_side_user_device_session_and_author_identity(): void
    {
        $viewer = User::factory()->create();
        $issued = $this->startUserSession($viewer, Device::factory()->create());
        $post = Post::factory()->create();
        $event = $this->event($post, 'impression');

        $response = $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', [
            'events' => [$event],
        ]);

        $response->assertOk()->assertJsonPath('accepted_event_ids.0', $event['event_id']);
        $this->assertDatabaseHas('content_events', [
            'event_uuid' => $event['event_id'],
            'user_id' => $viewer->id,
            'device_id' => $issued->session->device_id,
            'device_session_id' => $issued->session->id,
            'post_id' => $post->id,
            'author_id' => $post->user_id,
        ]);
    }

    public function test_duplicate_batches_are_idempotent(): void
    {
        $issued = $this->startUserSession(User::factory()->create());
        $event = $this->event(Post::factory()->create(), 'open');
        $payload = ['events' => [$event]];

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', $payload)->assertOk();
        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', $payload)->assertOk();

        $this->assertDatabaseCount('content_events', 1);
    }

    public function test_mismatched_post_author_and_inaccessible_posts_are_rejected_atomically(): void
    {
        $viewer = User::factory()->create();
        $issued = $this->startUserSession($viewer);
        $post = Post::factory()->create();
        $valid = $this->event($post, 'impression');
        $invalid = $this->event($post, 'open');
        $invalid['author_id'] = User::factory()->create()->id;

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', [
            'events' => [$valid, $invalid],
        ])->assertUnprocessable()->assertJsonValidationErrors('events.1.author_id');
        $this->assertDatabaseCount('content_events', 0);

        $post->user->forceFill(['account_visibility' => 'private'])->save();
        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', [
            'events' => [$this->event($post, 'impression')],
        ])->assertUnprocessable()->assertJsonValidationErrors('events.0.post_id');
    }

    public function test_client_identity_fields_unknown_metadata_and_invalid_event_shapes_are_rejected(): void
    {
        $issued = $this->startUserSession(User::factory()->create());
        $post = Post::factory()->create();
        $event = $this->event($post, 'dwell');
        $event['device_id'] = 999;
        $event['metadata'] = ['duration_ms' => 1000, 'secret_text' => 'must not store'];

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$event]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0', 'events.0.metadata']);

        $swipe = $this->event($post, 'carousel_swipe');
        $swipe['metadata'] = ['media_position' => 1];
        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$swipe]])
            ->assertUnprocessable()->assertJsonValidationErrors('events.0.metadata.direction');
        $this->assertDatabaseCount('content_events', 0);
    }

    public function test_event_time_must_fall_inside_the_active_device_session(): void
    {
        $issued = $this->startUserSession(User::factory()->create());
        $event = $this->event(Post::factory()->create(), 'impression');
        $event['occurred_at'] = $issued->session->started_at->copy()->subSecond()->toIso8601String();

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$event]])
            ->assertUnprocessable()->assertJsonValidationErrors('events.0.occurred_at');

        $issued->session->update(['ended_at' => now()]);
        $event['occurred_at'] = now()->toIso8601String();
        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => [$event]])
            ->assertUnauthorized();
    }

    public function test_analytics_events_never_mutate_authoritative_engagement_counters(): void
    {
        $issued = $this->startUserSession(User::factory()->create());
        $post = Post::factory()->create();

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', [
            'events' => [$this->event($post, 'like')],
        ])->assertOk();

        $this->assertDatabaseCount('likes', 0);
        $this->assertSame(0, $post->likes()->count());
    }

    public function test_batch_count_payload_size_and_rate_are_bounded(): void
    {
        $viewer = User::factory()->create();
        $issued = $this->startUserSession($viewer);
        $post = Post::factory()->create();
        $events = array_map(fn (): array => $this->event($post, 'impression'), range(1, 51));

        $this->withToken($issued->token)->postJson('/api/v1/analytics/content-events', ['events' => $events])
            ->assertUnprocessable()->assertJsonValidationErrors('events');

        $oversized = json_encode(['events' => [$this->event($post, 'impression')], 'padding' => str_repeat('x', 270_000)]);
        $this->withToken($issued->token)
            ->withHeader('Content-Type', 'application/json')
            ->call('POST', '/api/v1/analytics/content-events', [], [], [], [], (string) $oversized)
            ->assertStatus(413);

        $rateIssued = $this->startUserSession(User::factory()->create());
        Cache::flush();
        for ($index = 0; $index < 30; $index++) {
            $this->withToken($rateIssued->token)->postJson('/api/v1/analytics/content-events', [
                'events' => [$this->event($post, 'impression')],
            ])->assertOk();
        }
        $this->withToken($rateIssued->token)->postJson('/api/v1/analytics/content-events', [
            'events' => [$this->event($post, 'impression')],
        ])->assertTooManyRequests();
    }

    public function test_retention_command_removes_only_expired_raw_events(): void
    {
        $issued = $this->startUserSession(User::factory()->create());
        $old = $this->storedEvent($issued->session->user_id, $issued->session->device_id, $issued->session->id, now()->subDays(91));
        $recent = $this->storedEvent($issued->session->user_id, $issued->session->device_id, $issued->session->id, now());

        $this->artisan('analytics:prune-content-events')->assertSuccessful();

        $this->assertDatabaseMissing('content_events', ['id' => $old->id]);
        $this->assertDatabaseHas('content_events', ['id' => $recent->id]);
    }

    /** @return array<string, mixed> */
    private function event(Post $post, string $type): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'post_id' => $post->id,
            'author_id' => $post->user_id,
            'surface' => 'for_you_feed',
            'position' => 0,
            'candidate_source' => 'trending',
            'request_id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private function storedEvent(int $userId, int $deviceId, int $sessionId, mixed $receivedAt): ContentEvent
    {
        $author = User::factory()->create();

        return ContentEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'device_id' => $deviceId,
            'device_session_id' => $sessionId,
            'author_id' => $author->id,
            'surface' => 'profile',
            'event_type' => 'profile_open',
            'occurred_at' => $receivedAt,
            'received_at' => $receivedAt,
        ]);
    }
}
