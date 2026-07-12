<?php

namespace App\Actions\Telemetry;

use App\Models\Device;
use Illuminate\Http\Request;

/**
 * First-run enrollment: creates a Device and issues it a fresh Sanctum token. No prior
 * auth is possible here for a genuinely new device_uuid — this is how a device gets a
 * token in the first place.
 *
 * Re-registering an *existing* device_uuid is a different story: without this check,
 * anyone who learns/guesses a device_uuid could silently steal that device's identity
 * by re-registering unauthenticated — the old token (if any) gets deleted and a fresh
 * one handed to the attacker instead. So rotating an existing device's token always
 * requires presenting that exact device's current token via Authorization: Bearer —
 * proof of possession, not just knowledge of the UUID. This holds even for a device
 * that currently has *no* live token (e.g. deliberately revoked by support after a
 * compromise, or its token expired): a tokenless device is not up for grabs by
 * whoever asks next, since that would let anyone silently reclaim exactly the kind of
 * device a revocation was meant to lock out. There is deliberately no unauthenticated
 * recovery path for an existing device_uuid that lost its token — a real reinstall
 * (see CLAUDE.md) produces a brand new device_uuid anyway, which registers fine.
 */
class RegisterDevice
{
    /** @param  array<string, mixed>  $validated  Validated RegisterDeviceRequest data. */
    public function __invoke(Request $request, array $validated): DeviceRegistration
    {
        $device = Device::firstOrNew(['device_uuid' => $validated['device_id']]);
        $isNewDevice = ! $device->exists;

        if ($device->exists) {
            $authenticated = $request->user('sanctum');

            abort_unless(
                $authenticated instanceof Device && $authenticated->is($device),
                401,
                'This device is already registered. Re-registering it requires the current device token.',
            );
        }

        $device->fill([
            'manufacturer' => $validated['manufacturer'] ?? null,
            'brand' => $validated['brand'] ?? null,
            'model' => $validated['model'] ?? null,
            'os_name' => $validated['os_name'] ?? 'Android',
            'os_version' => $validated['os_version'] ?? null,
            'sdk_int' => $validated['sdk_int'] ?? null,
            'app_version_name' => $validated['app_version_name'] ?? null,
            'app_version_code' => $validated['app_version_code'] ?? null,
            'last_seen_at' => now(),
        ]);
        if ($isNewDevice) {
            $device->first_seen_at = now();
        }
        $device->save();

        // Sanctum tokens are hashed at rest and never retrievable again after creation, so
        // re-registering (e.g. app data cleared, or a fresh install with the same device_uuid
        // somehow) always revokes whatever existed before and mints a new one.
        $device->tokens()->delete();
        $token = $device->createToken('android-device')->plainTextToken;

        return new DeviceRegistration($device, $token, $isNewDevice);
    }
}
