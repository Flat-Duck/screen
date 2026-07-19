<?php

namespace App\Http\Resources;

use App\Models\Repost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A repost *event* — who reposted, an optional quote comment, and when — wrapping the
 * original post. Deliberately not shaped like `PostResource` directly, since "who reposted
 * this and when" is data a plain post listing doesn't have.
 *
 * @mixin Repost
 */
class RepostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comment' => $this->comment,
            'post' => new PostResource($this->whenLoaded('post')),
            'created_at' => $this->created_at,
        ];
    }
}
