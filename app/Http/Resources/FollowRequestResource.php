<?php

namespace App\Http\Resources;

use App\Models\FollowRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FollowRequest */
class FollowRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requester' => new UserSummaryResource($this->whenLoaded('requester')),
            'target' => new UserSummaryResource($this->whenLoaded('target')),
            'created_at' => $this->created_at,
        ];
    }
}
