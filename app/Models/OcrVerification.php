<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded comparison between a device's OCR claim and the server's own extraction —
 * or, for `VERDICT_UNVERIFIED`, a record that no comparison happened because the account was
 * trusted enough to skip it.
 *
 * Holds no OCR text by design: see the migration. Everything here is safe to keep forever,
 * which is the point — it is the only thing that makes a trend measurable.
 *
 * @property string $ocr_source
 * @property string $verdict
 * @property float|null $similarity
 */
class OcrVerification extends Model
{
    public const VERDICT_MATCH = 'match';

    public const VERDICT_MISMATCH = 'mismatch';

    /** A trusted account's claim, taken as canonical without the server re-reading the image. */
    public const VERDICT_UNVERIFIED = 'unverified';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'similarity' => 'float',
            'category_matched' => 'boolean',
            'device_char_count' => 'integer',
            'server_char_count' => 'integer',
        ];
    }

    /** @return BelongsTo<PostMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'post_media_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Only the rows where the server actually re-read the image — the ones an agreement rate
     * may be computed over. Unverified rows say nothing about accuracy and would inflate it.
     *
     * @param  Builder<OcrVerification>  $query
     * @return Builder<OcrVerification>
     */
    public function scopeCompared(Builder $query): Builder
    {
        return $query->whereIn('verdict', [self::VERDICT_MATCH, self::VERDICT_MISMATCH]);
    }
}
