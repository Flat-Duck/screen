<?php

namespace App\Models;

use Database\Factories\UserInviteLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInviteLink extends Model
{
    /** @use HasFactory<UserInviteLinkFactory> */
    use HasFactory;

    protected $fillable = ['inviter_user_id', 'token_hash'];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }
}
