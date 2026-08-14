<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per successful invite-code redemption at signup — `invitee_user_id` is unique
 * (one inviter per invitee, ever). `points_awarded_at` stays null until
 * `AwardMaturedInvitePoints` credits the inviter once the invitee has been active for the
 * configured maturity window; a null value means "redeemed, points not yet matured," not
 * "no points owed."
 *
 * @property int $id
 * @property int $inviter_user_id
 * @property int $invitee_user_id
 * @property string $code_used
 * @property Carbon $redeemed_at
 * @property Carbon|null $points_awarded_at
 * @property int|null $points_awarded
 * @property User $inviter
 * @property User|null $invitee Null when soft-deleted — SoftDeletes' own global scope excludes a
 *                              trashed invitee from this belongsTo lookup entirely, same as any other relation to User.
 */
class UserInvite extends Model
{
    protected $fillable = ['inviter_user_id', 'invitee_user_id', 'code_used', 'redeemed_at'];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'points_awarded_at' => 'datetime',
            'points_awarded' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }
}
