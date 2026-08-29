<?php

namespace App\Actions\Auth;

use App\Data\Auth\DeviceSessionContext;
use App\Data\Auth\RegisterUserData;
use App\Enums\LoginMethod;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\InviteCodeService;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(
        private readonly StartDeviceSession $startSession,
        private readonly InviteCodeService $inviteCodes,
    ) {}

    public function __invoke(Device $device, RegisterUserData $data, DeviceSessionContext $context): IssuedAccessToken
    {
        // Resolved before the user exists — throws (422, field invite_code) without creating
        // anything if a code was required-but-missing or present-but-invalid.
        $inviter = $this->inviteCodes->resolveOrFail($data->inviteCode);

        $user = DB::transaction(function () use ($data, $inviter): User {
            $user = User::create([
                'name' => $data->name,
                'username' => $data->username,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            if ($inviter !== null) {
                $this->inviteCodes->redeem($inviter, $user, (string) $data->inviteCode);
            }

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return ($this->startSession)($user, $device, LoginMethod::Registration, $context, isNewAccount: true);
    }
}
