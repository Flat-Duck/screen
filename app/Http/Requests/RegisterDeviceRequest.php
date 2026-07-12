<?php

namespace App\Http\Requests;

use App\Data\Telemetry\RegisterDeviceData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function toData(): RegisterDeviceData
    {
        $data = $this->validated();

        return new RegisterDeviceData(
            deviceId: (string) $data['device_id'],
            manufacturer: isset($data['manufacturer']) ? (string) $data['manufacturer'] : null,
            brand: isset($data['brand']) ? (string) $data['brand'] : null,
            model: isset($data['model']) ? (string) $data['model'] : null,
            osName: isset($data['os_name']) ? (string) $data['os_name'] : null,
            osVersion: isset($data['os_version']) ? (string) $data['os_version'] : null,
            sdkInt: isset($data['sdk_int']) ? (int) $data['sdk_int'] : null,
            appVersionName: isset($data['app_version_name']) ? (string) $data['app_version_name'] : null,
            appVersionCode: isset($data['app_version_code']) ? (int) $data['app_version_code'] : null,
        );
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * No prior auth exists yet at registration time — that's the point of this endpoint.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'sdk_int' => ['nullable', 'integer'],
            'app_version_name' => ['nullable', 'string', 'max:255'],
            'app_version_code' => ['nullable', 'integer'],
        ];
    }
}
