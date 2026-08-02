<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /**
     * The alt text `PublishMediaAnalysis` falls back to when the client doesn't send an explicit
     * override at publish time — collapsed whitespace, capped the same length as the manual
     * `alt_text` field ever accepted (`PublishMediaAnalysisRequest`'s `max:1000`). `null` once OCR
     * found no text at all (e.g. a photo with no text in it), same as an unset manual alt text —
     * never a placeholder string like "Screenshot".
     *
     * `null` whenever `safety_status` is `warning` too, deliberately — the whole point of that
     * flag is "this OCR'd text contains something like a credential or personal info that
     * shouldn't be echoed back" (see `SensitiveInformationAnalyzer`); auto-filling a public,
     * screen-reader-visible alt text straight from that same text would leak exactly what the
     * warning exists to catch. A "sensitive" post just gets no suggested alt text at all, same as
     * a photo with no OCR text — the poster can still type one manually if they want.
     */
    public function suggestedAltText(): ?string
    {
        if (! $this->ocr_text || $this->safety_status === PostMedia::SAFETY_WARNING) {
            return null;
        }

        $collapsed = trim(preg_replace('/\s+/u', ' ', (string) $this->ocr_text) ?? '');

        return $collapsed === '' ? null : Str::limit($collapsed, 1000);
    }
}
