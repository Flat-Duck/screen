<?php

namespace App\Http\Requests;

use App\Data\Devices\EnrollDeviceData;
use Illuminate\Foundation\Http\FormRequest;

class EnrollDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'uuid'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'os_name' => ['required', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'sdk_int' => ['nullable', 'integer'],
            'app_version_name' => ['nullable', 'string', 'max:255'],
            'app_version_code' => ['nullable', 'integer'],
        ];
    }

    public function toData(): EnrollDeviceData
    {
        $data = $this->validated();

        return new EnrollDeviceData(
            deviceUuid: (string) $data['device_uuid'],
            manufacturer: isset($data['manufacturer']) ? (string) $data['manufacturer'] : null,
            brand: isset($data['brand']) ? (string) $data['brand'] : null,
            model: isset($data['model']) ? (string) $data['model'] : null,
            osName: (string) $data['os_name'],
            osVersion: isset($data['os_version']) ? (string) $data['os_version'] : null,
            sdkInt: isset($data['sdk_int']) ? (int) $data['sdk_int'] : null,
            appVersionName: isset($data['app_version_name']) ? (string) $data['app_version_name'] : null,
            appVersionCode: isset($data['app_version_code']) ? (int) $data['app_version_code'] : null,
        );
    }
}
