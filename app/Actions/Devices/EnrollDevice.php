<?php

namespace App\Actions\Devices;

use App\Data\Devices\DeviceEnrollment;
use App\Data\Devices\EnrollDeviceData;
use App\Exceptions\DeviceProofOfPossessionRequired;
use App\Models\Device;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class EnrollDevice
{
    public function __invoke(EnrollDeviceData $data, ?Device $authenticatedDevice): DeviceEnrollment
    {
        $lock = Cache::lock('device-enrollment:'.hash('sha256', $data->deviceUuid), 30);
        $lock->block(5);

        try {
            try {
                return DB::transaction(function () use ($data, $authenticatedDevice): DeviceEnrollment {
                    $device = Device::query()->where('device_uuid', $data->deviceUuid)->lockForUpdate()->first();
                    $isNewDevice = $device === null;

                    if ($device && ! $authenticatedDevice?->is($device)) {
                        throw new DeviceProofOfPossessionRequired;
                    }

                    $device ??= new Device(['device_uuid' => $data->deviceUuid, 'first_seen_at' => now()]);
                    $device->fill([
                        'manufacturer' => $data->manufacturer,
                        'brand' => $data->brand,
                        'model' => $data->model,
                        'os_name' => $data->osName,
                        'os_version' => $data->osVersion,
                        'sdk_int' => $data->sdkInt,
                        'app_version_name' => $data->appVersionName,
                        'app_version_code' => $data->appVersionCode,
                        'last_seen_at' => now(),
                    ])->save();

                    $device->tokens()->delete();
                    $token = $device->createToken('device-installation', [
                        'device:manage',
                        'telemetry:write',
                        'push-token:write',
                    ])->plainTextToken;

                    return new DeviceEnrollment($device, $token, $isNewDevice);
                });
            } catch (QueryException $exception) {
                if ($authenticatedDevice === null && in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw new DeviceProofOfPossessionRequired;
                }

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }
}
