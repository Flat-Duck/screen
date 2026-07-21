<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Rules\SafeSourceUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Callers must `loadCount(['likes', 'comments'])` and set `is_liked`/`is_saved` on the
 * model for the current viewer (see LikeService/SavedPostService/PostQueryService) before
 * resourcing.
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
            'comments_enabled' => $this->comments_enabled,
            'reposts_enabled' => $this->reposts_enabled,
            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ]),
            'source_application' => $this->source_application,
            'source_url' => SafeSourceUrl::isSafe($this->source_url) ? $this->source_url : null,
            'content_warning' => $this->content_warning,
            'is_liked' => (bool) ($this->is_liked ?? false),
            'is_saved' => (bool) ($this->is_saved ?? false),
            'created_at' => $this->created_at,
            'edited_at' => $this->edited_at,
            'archived_at' => $this->archived_at,
            'deleted_at' => $this->deleted_at,
            'scheduled_purge_at' => $this->deleted_at?->copy()->addDays((int) config('social.post_retention_days', 30)),
            'recommendation' => $this->when($this->recommendation !== null, $this->recommendation),
        ];
    }
}
