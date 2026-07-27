<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property bool|null $is_member Set per-request by GroupService for the current viewer — not a DB column. */
class Group extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['member_count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return HasMany<GroupPost, $this> */
    public function groupPosts(): HasMany
    {
        return $this->hasMany(GroupPost::class);
    }

    public function isMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->where('user_id', $user->id)->value('role');
    }
}
