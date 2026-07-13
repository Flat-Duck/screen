<?php

namespace Tests\Feature;

use App\Actions\Auth\CloseDeviceSession;
use App\Enums\SessionEndReason;
use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TelemetryApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function enrollmentPayload(?string $uuid = null): array
    {
        return [
            'device_uuid' => $uuid ?? (string) Str::uuid(),
            'manufacturer' => 'Google',
            'brand' => 'google',
            'model' => 'Pixel 8',
            'os_name' => 'Android',
            'os_version' => '14',
            'sdk_int' => 34,
            'app_version_name' => '3.0',
            'app_version_code' => 30,
        ];
    }

    /** @return array<string, mixed> */
    private function telemetryPayload(array $events): array
    {
        return [
            'app' => ['version_name' => '3.0', 'version_code' => 30, 'build_type' => 'release'],
            'os_version' => '14',
            'events' => $events,
        ];
    }

    /** @return array<string, mixed> */
    private function crashEvent(array $overrides = []): array
    {
        return array_replace_recursive([
            'event_id' => (string) Str::uuid(),
            'kind' => 'fatal_crash',
            'name' => 'fatal_crash',
            'occurred_at' => now()->toIso8601String(),
            'extras' => [],
            'breadcrumbs' => [],
            'error' => [
                'tag' => 'FatalCrashHandler.uncaughtException',
                'exception_class' => 'java.lang.IllegalStateException',
                'message' => 'boom',
                'stack_trace' => "java.lang.IllegalStateException: boom\n at Foo.bar(Foo.kt:42)",
                'thread_name' => 'main',
                'is_fatal' => true,
            ],
        ], $overrides);
    }

    public function test_new_installation_enrols_and_receives_restricted_device_token(): void
    {
        $response = $this->postJson('/api/v1/devices/enroll', $this->enrollmentPayload());

        $response->assertCreated()->assertJsonStructure(['device_uuid', 'token']);
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['device:manage', 'telemetry:write', 'push-token:write'], $token?->abilities);
    }

    public function test_existing_installation_requires_and_rotates_its_current_credential(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->postJson('/api/v1/devices/enroll', $this->enrollmentPayload($uuid))->assertCreated();

        $this->postJson('/api/v1/devices/enroll', $this->enrollmentPayload($uuid))->assertUnauthorized();
        $second = $this->withHeader('Authorization', 'Bearer '.$first->json('token'))
            ->postJson('/api/v1/devices/enroll', $this->enrollmentPayload($uuid))
            ->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame($first->json('token'), $second->json('token'));
    }

    public function test_pre_login_device_can_report_a_crash(): void
    {
        $device = $this->authenticateDevice();
        $event = $this->crashEvent();

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload([$event]))
            ->assertOk()
            ->assertJson(['accepted_event_ids' => [$event['event_id']]]);

        $this->assertDatabaseHas('telemetry_events', ['device_id' => $device->id, 'user_id' => null]);
    }

    public function test_valid_session_attributes_crash_to_user_and_session(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();
        $session = $this->startUserSession($user, $device)->session;
        $this->authenticateDevice($device);
        $event = $this->crashEvent(['session_id' => $session->uuid]);

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload([$event]))->assertOk();

        $this->assertDatabaseHas('telemetry_events', [
            'device_id' => $device->id,
            'user_id' => $user->id,
            'device_session_id' => $session->id,
        ]);
    }

    public function test_cross_device_session_reference_falls_back_to_anonymous_device_attribution(): void
    {
        $foreignSession = $this->startUserSession(User::factory()->create())->session;
        $device = $this->authenticateDevice();

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload([
            $this->crashEvent(['session_id' => $foreignSession->uuid]),
        ]))->assertOk();

        $this->assertDatabaseHas('telemetry_events', ['device_id' => $device->id, 'user_id' => null, 'device_session_id' => null]);
    }

    public function test_delayed_crash_inside_a_closed_session_window_keeps_attribution(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();
        $session = $this->startUserSession($user, $device)->session;
        app(CloseDeviceSession::class)($session, SessionEndReason::Logout);
        $this->authenticateDevice($device);

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload([
            $this->crashEvent(['session_id' => $session->uuid, 'occurred_at' => $session->started_at->addSecond()->toIso8601String()]),
        ]))->assertOk();

        $this->assertDatabaseHas('telemetry_events', ['user_id' => $user->id, 'device_session_id' => $session->id]);
    }

    public function test_sensitive_context_is_redacted_and_crashes_are_fingerprinted(): void
    {
        $this->authenticateDevice();
        $event = $this->crashEvent([
            'extras' => ['authorization' => 'Bearer secret-token', 'email' => 'ada@example.com'],
            'error' => ['message' => 'contact ada@example.com'],
        ]);

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload([$event]))->assertOk();
        $stored = TelemetryEvent::firstOrFail();

        $this->assertSame('[REDACTED]', $stored->extras['authorization']);
        $this->assertStringNotContainsString('ada@example.com', $stored->error_message);
        $this->assertNotNull($stored->crash_fingerprint);
        $this->assertSame(30, $stored->app_version_code);
    }

    public function test_batch_is_limited_to_fifty_events(): void
    {
        $this->authenticateDevice();
        $events = array_map(fn () => $this->crashEvent(), range(1, 51));

        $this->postJson('/api/v1/telemetry/events', $this->telemetryPayload($events))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);
    }

    public function test_payload_is_limited_to_512_kilobytes(): void
    {
        $this->authenticateDevice();
        $payload = $this->telemetryPayload([$this->crashEvent(['extras' => ['blob' => str_repeat('x', 530_000)]])]);

        $this->postJson('/api/v1/telemetry/events', $payload)->assertStatus(413);
    }

    public function test_resending_event_uuid_is_idempotent(): void
    {
        $this->authenticateDevice();
        $payload = $this->telemetryPayload([$this->crashEvent()]);

        $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
        $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
        $this->assertDatabaseCount('telemetry_events', 1);
    }

    public function test_event_uuid_deduplication_is_scoped_to_the_authenticated_device(): void
    {
        $event = $this->crashEvent();
        $payload = $this->telemetryPayload([$event]);

        $this->authenticateDevice();
        $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();

        $this->authenticateDevice();
        $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();

        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertSame(2, TelemetryEvent::query()->where('event_uuid', $event['event_id'])->distinct('device_id')->count('device_id'));
    }
}
