<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A short-lived proof that `code` was validated — not a redemption in its own right. See
 * `InviteCodeService::reserve()`/`resolveTicket()`. Layered in front of the existing
 * unlimited-use `users.invite_code` referral system, purely so the signup screen doesn't have to
 * re-validate a raw code the invite-gate screen already confirmed; the underlying code stays
 * reusable by anyone else regardless of how many reservations exist for it, expired or not.
 * `ticket` (not `code`) is what the client carries forward into `POST /v1/auth/register`/
 * `POST /v1/auth/social/google`. Pruned by `invites:prune-reservations` once past `expires_at`.
 *
 * @property int $id
 * @property string $code
 * @property string $ticket
 * @property Carbon $expires_at
 */
class InviteReservation extends Model
{
    protected $fillable = ['code', 'ticket', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
