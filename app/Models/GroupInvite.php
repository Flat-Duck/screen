<?php

namespace App\Models;

use App\Enums\GroupInviteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $group_id
 * @property int $inviter_id
 * @property int $invitee_id
 * @property GroupInviteStatus $status
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Group $group
 * @property User $inviter
 * @property User $invitee
 */
class GroupInvite extends Model
{
    protected $fillable = ['group_id', 'inviter_id', 'invitee_id', 'status', 'responded_at'];

    protected function casts(): array
    {
        return [
            'status' => GroupInviteStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }
}
