<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Upload */
class UploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'upload_id' => $this->upload_id,
            'object_key' => $this->object_key,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
        ];
    }
}
