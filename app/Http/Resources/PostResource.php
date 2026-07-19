<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Callers must `loadCount(['likes', 'comments'])` and set `is_liked` on the model
 * for the current viewer (see LikeService/PostQueryService) before resourcing.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'caption' => $this->caption,
            'status' => $this->status,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'media' => PostMediaResource::collection($this->whenLoaded('media')),
            'likes_count' => $this->likes_count,
            'comments_count' => $this->comments_count,
            'is_liked' => (bool) ($this->is_liked ?? false),
            'created_at' => $this->created_at,
            'edited_at' => $this->edited_at,
        ];
    }
}
