<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rolling per-user counter, not a moderation action — see the migration's comment on why this
 * is deliberately separate from user_restrictions. Read/written only by OcrTrustSampler.
 */
class UserOcrTrust extends Model
{
    public const TIER_NEW = 'new';

    public const TIER_TRUSTED = 'trusted';

    public const TIER_PROBATION = 'probation';

    protected $table = 'user_ocr_trust';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'trust_tier', 'consecutive_verified_count', 'last_mismatch_at',
    ];

    protected function casts(): array
    {
        return [
            'consecutive_verified_count' => 'integer',
            'last_mismatch_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
