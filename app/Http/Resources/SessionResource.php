<?php

namespace App\Http\Resources;

use App\Models\DeviceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeviceSession
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->uuid,
            'login_method' => $this->login_method,
            'device' => [
                'device_uuid' => $this->device->device_uuid,
                'manufacturer' => $this->device->manufacturer,
                'brand' => $this->device->brand,
                'model' => $this->device->model,
                'os_name' => $this->device->os_name,
                'os_version' => $this->os_version,
                'app_version_name' => $this->app_version_name,
                'app_version_code' => $this->app_version_code,
            ],
            'last_seen_at' => $this->last_seen_at,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'end_reason' => $this->end_reason,
            'two_factor_verified_at' => $this->two_factor_verified_at,
            'revoked_at' => $this->revoked_at,
            'status' => $this->ended_at === null ? 'active' : 'ended',
            'is_revoked' => $this->revoked_at !== null,
            'is_current' => $this->personal_access_token_id === $request->user()?->currentAccessToken()?->id,
        ];
    }
}
