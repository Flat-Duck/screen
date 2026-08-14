<?php

namespace App\Http\Resources;

use App\Models\UserInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserInvite */
class UserInviteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invitee' => new UserSummaryResource($this->whenLoaded('invitee')),
            'redeemed_at' => $this->redeemed_at,
            // "matured" rather than "pending"/"awarded" as a single boolean — the client only
            // ever needs to know whether points have landed yet, not the exact amount per row
            // (the running total is already on the user's own points_balance).
            'points_matured' => $this->points_awarded_at !== null,
            'points_awarded' => $this->points_awarded,
        ];
    }
}
