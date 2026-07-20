<?php

namespace App\Http\Resources;

use App\Models\UserHiddenTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserHiddenTerm */
class HiddenTermResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'value' => $this->original_value,
            'type' => $this->type->value,
            'created_at' => $this->created_at,
        ];
    }
}
