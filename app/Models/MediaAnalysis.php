<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
class MediaAnalysis extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MediaAnalysisItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MediaAnalysisItem::class)->orderBy('position');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
