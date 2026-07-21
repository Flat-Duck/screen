<?php

namespace App\Http\Resources;

use App\Models\MediaAnalysis;
use App\Models\PostMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin MediaAnalysis */
class MediaAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');

        return [
            'token' => $this->token,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'requires_acknowledgement' => $items instanceof Collection
                && $items->contains(fn ($item): bool => $item->safety_status === PostMedia::SAFETY_WARNING),
            'items' => $items instanceof Collection ? $items->map(fn ($item): array => [
                'position' => $item->position,
                'status' => $item->ocr_status,
                'safety_status' => $item->safety_status,
                'findings' => $item->findings ?? [],
            ])->values() : [],
        ];
    }
}
