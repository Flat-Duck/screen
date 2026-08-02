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
                // Client pre-fills its editable alt-text field with this once the item is
                // "ready" — see MediaAnalysisItem::suggestedAltText(). Still null while OCR is
                // processing (ocr_text isn't set yet) or once ready if OCR found no text at all.
                'suggested_alt_text' => $item->suggestedAltText(),
            ])->values() : [],
        ];
    }
}
