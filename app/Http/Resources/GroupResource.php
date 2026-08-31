<?php

namespace App\Http\Resources;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Callers must set `is_member`/`is_admin` on the model for the current viewer
 * (see GroupService::annotateViewer) before resourcing.
 *
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            $viewer = $this->creator()->firstOrFail();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'is_discoverable' => (bool) $this->is_discoverable,
            'photo_url' => $this->photoUrl($viewer),
            'member_count' => $this->member_count,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'is_member' => (bool) ($this->is_member ?? false),
            'is_admin' => (bool) ($this->is_admin ?? false),
            'created_at' => $this->created_at,
        ];
    }
}
