<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\RevokeUserSessions;
use App\Concerns\PasswordValidationRules;
use App\Enums\SessionEndReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => $input['password'],
                // A valid single-use reset token delivered to this address proves ownership and
                // lets the real mailbox owner recover an address pre-registered by somebody else.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            ($this->revokeSessions)($user, SessionEndReason::PasswordReset);
        });
    }
}
