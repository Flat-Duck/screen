<?php

namespace App\Http\Resources;

use App\Models\PostMedia;
use App\Models\User;
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
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            $viewer = $this->post()->firstOrFail()->user()->firstOrFail();
        }

        return [
            'id' => $this->id,
            'position' => $this->position,
            // Thumbnail when ready, original as a fallback — the client always has something
            // to render even while status=processing, so this is never a blocking state.
            'url' => $this->thumbnailUrl($viewer) ?? $this->originalUrl($viewer),
            'original_url' => $this->originalUrl($viewer),
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status,
            'alt_text' => $this->alt_text,
            'safety_status' => $this->safety_status,
        ];
    }
}
