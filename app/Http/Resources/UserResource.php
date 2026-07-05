<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full public profile. Callers must `loadCount(['posts', 'followers', 'following'])` before
 * resourcing, and set `is_following` on the model when the viewer differs from the profile
 * being viewed (see ProfileService/UserController) — it's meaningless on your own profile.
 *
 * @mixin User
 */
class UserResource extends JsonResource
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
            'bio' => $this->bio,
            'avatar_url' => $this->avatarUrl(),
            'posts_count' => $this->posts_count,
            'followers_count' => $this->followers_count,
            'following_count' => $this->following_count,
            'is_following' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): bool => (bool) ($this->is_following ?? false),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
