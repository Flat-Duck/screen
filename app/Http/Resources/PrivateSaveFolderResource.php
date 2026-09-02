<?php

namespace App\Http\Resources;

use App\Models\PrivateSaveFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PrivateSaveFolder
 */
class PrivateSaveFolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'position' => $this->position,
            // Only present where the caller asked for counts (the folder listing); nested inside
            // a save the number would be meaningless, so the key is omitted rather than zeroed.
            'saves_count' => $this->whenNotNull($this->private_saves_count),
        ];
    }
}
