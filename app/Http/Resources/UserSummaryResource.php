<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight "who" for embedding in posts/comments — avoids N+1 count queries when
 * a full UserResource (with follow/post counts) isn't needed.
 *
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl(),
            'account_visibility' => $this->account_visibility->value,
        ];
    }
}
