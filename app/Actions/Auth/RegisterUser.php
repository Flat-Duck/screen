<?php

namespace App\Actions\Auth;

use App\Data\Auth\DeviceSessionContext;
use App\Data\Auth\RegisterUserData;
use App\Enums\LoginMethod;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;

class RegisterUser
{
    public function __construct(private readonly StartDeviceSession $startSession) {}

    public function __invoke(Device $device, RegisterUserData $data, DeviceSessionContext $context): IssuedAccessToken
    {
        $user = User::create([
            'name' => $data->name,
            'username' => $data->username,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        return ($this->startSession)($user, $device, LoginMethod::Registration, $context, isNewAccount: true);
    }
}
