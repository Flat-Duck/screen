<?php

namespace App\Models;

use App\Enums\FollowRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $requester_id
 * @property int $target_id
 * @property FollowRequestStatus $status
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property User $requester
 * @property User $target
 */
class FollowRequest extends Model
{
    protected $fillable = ['requester_id', 'target_id', 'status', 'responded_at'];

    protected function casts(): array
    {
        return [
            'status' => FollowRequestStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}
