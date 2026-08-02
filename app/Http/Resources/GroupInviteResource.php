<?php

namespace App\Http\Resources;

use App\Models\GroupInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GroupInvite */
class GroupInviteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'group' => new GroupResource($this->whenLoaded('group')),
            'inviter' => new UserSummaryResource($this->whenLoaded('inviter')),
            'created_at' => $this->created_at,
        ];
    }
}
