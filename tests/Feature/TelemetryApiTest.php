<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
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

    public function test_re_registering_the_same_device_with_its_current_token_reissues_a_token_without_duplicating_the_device(): void
    {
        $deviceId = (string) Str::uuid();

        $first = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));
        $first->assertCreated();

        $second = $this->withHeader('Authorization', "Bearer {$first->json('token')}")
            ->postJson('/api/telemetry/register', $this->registerPayload($deviceId));
        $second->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame($first->json('token'), $second->json('token'));
    }

    /**
     * Without proof of possession, re-registering an already-enrolled device_uuid would let
     * anyone who learns/guesses it steal that device's identity: the old token gets deleted
     * and a fresh one handed to whoever asked. Knowing the UUID alone must not be enough.
     */
    public function test_re_registering_an_already_enrolled_device_without_its_token_is_rejected(): void
    {
        $deviceId = (string) Str::uuid();

        $first = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));
        $first->assertCreated();

        $second = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));

        $second->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The rejected attempt didn't revoke the legitimate device's original token — it
        // still resolves via Sanctum's own token lookup, unchanged.
        $token = PersonalAccessToken::findToken($first->json('token'));
        $this->assertNotNull($token);
    }

    public function test_re_registering_an_already_enrolled_device_with_a_different_devices_token_is_rejected(): void
    {
        $deviceId = (string) Str::uuid();
        $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId))->assertCreated();

        $otherDevice = $this->postJson('/api/telemetry/register', $this->registerPayload());
        $otherDevice->assertCreated();

        $response = $this->withHeader('Authorization', "Bearer {$otherDevice->json('token')}")
            ->postJson('/api/telemetry/register', $this->registerPayload($deviceId));

        $response->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    /**
     * A device with no live token (e.g. deliberately revoked by support after a
     * compromise) must NOT be unauthenticated-reclaimable — that would let anyone
     * silently take over exactly the kind of device a revocation was meant to lock
     * out. There is no unauthenticated recovery path for an existing device_uuid once
     * its token is gone; a real reinstall produces a new device_uuid instead.
     */
    public function test_re_registering_a_device_with_no_existing_token_is_still_rejected(): void
    {
        $deviceId = (string) Str::uuid();
        $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId))->assertCreated();

        Device::where('device_uuid', $deviceId)->first()->tokens()->delete();

        $second = $this->postJson('/api/telemetry/register', $this->registerPayload($deviceId));

        $second->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
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
