<?php

namespace App\Http\Resources;

use App\Models\Hashtag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Callers should `withCount('posts')` before resourcing if `posts_count` is needed —
 * omitted (falls back to 0) otherwise, same convention as PostResource's counts.
 *
 * @mixin Hashtag
 */
class HashtagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'posts_count' => $this->posts_count ?? 0,
            'is_followed' => (bool) ($this->is_followed ?? false),
        ];
    }
}
