<?php

namespace App\Models;

use App\Enums\UserRestrictionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property UserRestrictionType $type
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $reason
 * @property Carbon|null $revoked_at
 */
class UserRestriction extends Model
{
    protected $fillable = ['user_id', 'type', 'starts_at', 'ends_at', 'reason', 'moderation_case_id', 'created_by', 'revoked_at', 'revoked_by', 'revocation_reason'];

    protected function casts(): array
    {
        return ['type' => UserRestrictionType::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param Builder<UserRestriction> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where(fn ($active) => $active->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return BelongsTo<ModerationCase, $this> */
    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class);
    }
}
