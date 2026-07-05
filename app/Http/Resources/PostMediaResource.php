<?php

namespace App\Http\Resources;

use App\Models\PostMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostMedia
 */
class PostMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            // Thumbnail when ready, original as a fallback — the client always has something
            // to render even while status=processing, so this is never a blocking state.
            'url' => $this->thumbnailUrl() ?? $this->originalUrl(),
            'original_url' => $this->originalUrl(),
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status,
        ];
    }
}
