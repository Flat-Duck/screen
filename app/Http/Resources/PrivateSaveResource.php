<?php

namespace App\Http\Resources;

use App\Models\PrivateSave;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PrivateSave
 */
class PrivateSaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            $viewer = $this->user()->firstOrFail();
        }

        return [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'folder' => $this->whenLoaded('folder', fn (): PrivateSaveFolderResource => new PrivateSaveFolderResource($this->folder)),
            'url' => $this->url($viewer),
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at,
        ];
    }
}
