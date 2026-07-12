<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum personal access token, presented as a "session/device" — its `name` column
 * is whatever `device_name` the client sent at register/login/social sign-in (see
 * AuthService/SocialAuthService), which is why it's exposed here as `device_name`.
 *
 * @mixin PersonalAccessToken
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->name,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'is_current' => $this->id === $request->user()?->currentAccessToken()?->id,
        ];
    }
}
