<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Echo back the client-facing alias, not the internal FQCN.
            'reportable_type' => array_search($this->reportable_type, Report::REPORTABLE_TYPES, true) ?: $this->reportable_type,
            'reason' => $this->reason,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
