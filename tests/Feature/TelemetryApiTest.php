<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelemetryApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(?string $deviceId = null): array
    {
        return [
            'device_id' => $deviceId ?? (string) Str::uuid(),
            'manufacturer' => 'Google',
            'brand' => 'google',
            'model' => 'Pixel 8',
            'os_name' => 'Android',
            'os_version' => '14',
            'sdk_int' => 34,
            'app_version_name' => '1.0',
            'app_version_code' => 1,
        ];
    }

    public function test_registering_a_new_device_creates_it_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/telemetry/register', $this->registerPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['device_id', 'token']);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_re_registering_the_same_device_reissues_a_token_without_duplicating_the_device(): void
    {
        $deviceId = (string) Str::uuid();

        $first = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));
        $first->assertCreated();

        $second = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));
        $second->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame($first->json('token'), $second->json('token'));
    }

    public function test_events_endpoint_rejects_unauthenticated_requests(): void
    {
        $response = $this->postJson('/api/telemetry/events', [
            'device' => $this->registerPayload(),
            'events' => [],
        ]);

        $response->assertUnauthorized();
    }

    public function test_events_endpoint_accepts_a_batch_and_updates_device_metadata(): void
    {
        $device = Device::factory()->create(['app_version_code' => 1]);
        Sanctum::actingAs($device);

        $eventId = (string) Str::uuid();
        $crashId = (string) Str::uuid();

        $response = $this->postJson('/api/telemetry/events', [
            'device' => array_merge($this->registerPayload($device->device_uuid), ['app_version_code' => 2]),
            'events' => [
                [
                    'event_id' => $eventId,
                    'kind' => 'event',
                    'name' => 'screenshot_detected',
                    'occurred_at' => now()->toIso8601String(),
                    'extras' => ['relative_path' => 'Pictures/Screenshots/'],
                    'breadcrumbs' => [],
                ],
                [
                    'event_id' => $crashId,
                    'kind' => 'fatal_crash',
                    'name' => 'fatal_crash',
                    'occurred_at' => now()->toIso8601String(),
                    'error' => [
                        'tag' => 'FatalCrashHandler.uncaughtException',
                        'exception_class' => 'java.lang.IllegalStateException',
                        'message' => 'boom',
                        'stack_trace' => "java.lang.IllegalStateException: boom\n\tat Foo.bar(Foo.kt:1)",
                        'thread_name' => 'main',
                        'is_fatal' => true,
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['accepted_event_ids' => [$eventId, $crashId]]);
        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertDatabaseHas('telemetry_events', ['event_uuid' => $crashId, 'is_fatal' => true]);
        $this->assertSame(2, $device->fresh()->app_version_code);
    }

    public function test_resending_the_same_event_uuid_does_not_duplicate_the_row(): void
    {
        $device = Device::factory()->create();
        Sanctum::actingAs($device);

        $eventId = (string) Str::uuid();
        $payload = [
            'device' => $this->registerPayload($device->device_uuid),
            'events' => [[
                'event_id' => $eventId,
                'kind' => 'event',
                'name' => 'screenshot_detected',
                'occurred_at' => now()->toIso8601String(),
            ]],
        ];

        $this->postJson('/api/telemetry/events', $payload)->assertOk();
        $this->postJson('/api/telemetry/events', $payload)->assertOk();

        $this->assertDatabaseCount('telemetry_events', 1);
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $device = Device::factory()->create();
        Sanctum::actingAs($device);

        $response = $this->postJson('/api/telemetry/events', [
            'device' => $this->registerPayload($device->device_uuid),
            'events' => [[
                'event_id' => (string) Str::uuid(),
                'kind' => 'not_a_real_kind',
                'name' => 'x',
                'occurred_at' => now()->toIso8601String(),
            ]],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['events.0.kind']);
    }
}
