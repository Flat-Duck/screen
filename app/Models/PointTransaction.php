<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit ledger backing `users.points_balance` — every balance change is a row
 * here, same "cached denormalized counter + audit trail" convention as `AdminAuditLog`
 * backing admin actions. `reason` is currently always `referral_bonus`, kept as an open
 * string rather than an enum since points sources are expected to grow.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property int|null $user_invite_id
 */
class PointTransaction extends Model
{
    public const REASON_REFERRAL_BONUS = 'referral_bonus';

    protected $fillable = ['user_id', 'amount', 'reason', 'user_invite_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserInvite, $this> */
    public function userInvite(): BelongsTo
    {
        return $this->belongsTo(UserInvite::class);
    }
}
