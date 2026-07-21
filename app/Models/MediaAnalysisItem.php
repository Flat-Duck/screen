<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAnalysisItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'ocr_text' => 'encrypted',
            'findings' => 'array',
        ];
    }

    /** @return BelongsTo<MediaAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(MediaAnalysis::class, 'media_analysis_id');
    }
}
