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
            'country_code' => $this->country_code,
            'account_visibility' => $this->account_visibility->value,
            // Unlike every other field here, birth_date is only ever included on your
            // own profile — it's PII with no reason to be visible to other viewers, even
            // though the rest of this resource is otherwise identical for "me" vs.
            // "someone else's public profile" (see UserController::show).
            'birth_date' => $this->when(
                $request->user()?->is($this->resource),
                fn (): ?string => $this->birth_date?->format('Y-m-d'),
            ),
            'posts_count' => $this->posts_count,
            'followers_count' => $this->followers_count,
            'following_count' => $this->following_count,
            'is_following' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): bool => (bool) ($this->is_following ?? false),
            ),
            'follows_you' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): bool => (bool) ($this->follows_you ?? false),
            ),
            'follow_request_status' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): ?string => $this->follow_request_status ?? null,
            ),
            'is_blocked' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): bool => (bool) ($this->is_blocked ?? false),
            ),
            'is_blocked_by' => $this->when(
                $request->user() && $request->user()->isNot($this->resource),
                fn (): bool => (bool) ($this->is_blocked_by ?? false),
            ),
            'created_at' => $this->created_at,
            'onboarding' => $this->when(
                $request->user()?->is($this->resource),
                fn (): array => [
                    'interests_completed' => $this->interests_completed_at !== null,
                    'interests_skipped' => $this->interests_skipped_at !== null,
                    'needs_interest_selection' => $this->needsInterestOnboarding(),
                ],
            ),
        ];
    }
}
