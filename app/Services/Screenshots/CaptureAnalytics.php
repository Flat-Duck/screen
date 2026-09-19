<?php

namespace App\Services\Screenshots;

use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CaptureAnalytics
{
    public const CLIENT_STAGES = [
        'detected', 'overlay_shown', 'ignored_timeout', 'ignored_dismissed',
        'overlay_replaced', 'overlay_unavailable', 'overlay_interrupted',
        'share_tapped', 'private_save_tapped', 'share_cancelled', 'private_save_cancelled',
        'share_failed', 'private_save_failed',
    ];

    /** May precede detection because the telemetry queue uploads asynchronously. */
    public function claim(string $captureId, int $deviceId): void
    {
        DB::table('screenshot_captures')->insertOrIgnore([
            'id' => $captureId, 'device_id' => $deviceId, 'created_at' => now(),
        ]);
        $capture = DB::table('screenshot_captures')->where('id', $captureId)->first();
        if ((int) $capture->device_id !== $deviceId) {
            throw ValidationException::withMessages(['capture_id' => 'Invalid screenshot capture.']);
        }
    }

    public function ingest(TelemetryEvent $event): void
    {
        if ($event->kind !== 'event') {
            return;
        }
        $extras = $event->extras ?? [];
        if ($event->name === 'screenshot_capture_v1') {
            $id = $extras['capture_id'];
            $stage = $extras['stage'];
            $this->claim($id, $event->device_id);
            if ($stage === 'detected') {
                DB::table('screenshot_captures')->where('id', $id)->whereNull('detected_at')->update([
                    'detected_at' => $event->occurred_at, 'user_id' => $event->user_id,
                ]);
            }
            $this->stage($id, $stage, $event->user_id, CarbonImmutable::instance($event->occurred_at));
        } elseif ($event->name === 'screenshot_library_v1') {
            // Lock the installation so concurrent/out-of-order uploads cannot replace newer data.
            Device::query()->whereKey($event->device_id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('screenshot_library_snapshots')->where('device_id', $event->device_id)->first();
            if ($previous && CarbonImmutable::parse($previous->observed_at)->gte($event->occurred_at)) {
                return;
            }
            DB::table('screenshot_library_snapshots')->updateOrInsert(['device_id' => $event->device_id], [
                'user_id' => $event->user_id,
                'screenshot_count' => isset($extras['count']) ? (int) $extras['count'] : null,
                'coverage' => $extras['coverage'], 'observed_at' => $event->occurred_at,
            ]);
        }
    }

    public function session(Request $request): DeviceSession
    {
        $token = $request->user()?->currentAccessToken();
        $key = $token?->getKey();
        $session = (is_int($key) || (is_string($key) && ctype_digit($key)))
            ? DeviceSession::query()->where('personal_access_token_id', $key)
                ->where('user_id', $request->user()->getAuthIdentifier())->whereNull('ended_at')->first()
            : null;
        if (! $session) {
            throw ValidationException::withMessages(['capture_id' => 'A device-linked session is required.']);
        }

        return $session;
    }

    public function complete(Request $request, string $stage): void
    {
        $id = $request->input('capture_id');
        if ($id === null) {
            return;
        }
        $session = $this->session($request);
        $this->claim($id, $session->device_id);
        $this->stage($id, $stage, $session->user_id, CarbonImmutable::now());
    }

    private function stage(string $id, string $stage, ?int $userId, CarbonImmutable $at): void
    {
        DB::table('screenshot_capture_stages')->insertOrIgnore([
            'capture_id' => $id, 'stage' => $stage, 'user_id' => $userId, 'occurred_at' => $at,
        ]);
    }
}
