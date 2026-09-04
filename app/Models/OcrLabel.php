<?php

namespace App\Models;

use App\Enums\OcrLabelVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's verdict on one specific OCR extraction. See the migration for why the engine
 * details are snapshotted onto the row rather than read from the media.
 *
 * @property OcrLabelVerdict $verdict
 * @property string $ocr_text_hash
 */
class OcrLabel extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verdict' => OcrLabelVerdict::class,
            'ocr_char_count' => 'integer',
        ];
    }

    /** The hash a label carries when the extraction produced no text at all. */
    public static function hashFor(?string $text): string
    {
        return hash('sha256', $text ?? '');
    }

    /** @return BelongsTo<PostMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'post_media_id');
    }

    /** @return BelongsTo<User, $this> */
    public function labeler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'labeled_by');
    }

    /**
     * Labels that still describe the media's current text. A re-run under a new engine or
     * language produces different output, and counting a verdict collected about the old
     * output as evidence about the new one would quietly launder stale ground truth.
     *
     * @param  Builder<OcrLabel>  $query
     * @return Builder<OcrLabel>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('post_media')
                ->whereColumn('post_media.id', 'ocr_labels.post_media_id')
                ->whereColumn('post_media.ocr_version', 'ocr_labels.engine_version');
        });
    }
}
