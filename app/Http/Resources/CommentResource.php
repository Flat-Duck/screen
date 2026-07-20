<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'body' => ($this->is_filtered ?? false) ? null : $this->body,
            'is_filtered' => (bool) ($this->is_filtered ?? false),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'replies_count' => $this->replies_count ?? 0,
            'likes_count' => $this->likes_count ?? 0,
            'is_liked' => (bool) ($this->is_liked ?? false),
            'created_at' => $this->created_at,
        ];
    }
}
