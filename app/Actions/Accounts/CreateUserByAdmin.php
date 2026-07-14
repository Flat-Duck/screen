<?php

namespace App\Actions\Accounts;

use App\Data\Auth\RegisterUserData;
use App\Models\User;

/**
 * Admin-initiated account creation from the dashboard's user-management page — unlike
 * `App\Actions\Auth\RegisterUser`, this never starts a device session or issues a
 * token; it just creates the row. Reuses `RegisterUserData` since the fields are
 * identical (name/username/email/password).
 */
final class CreateUserByAdmin
{
    public function __invoke(RegisterUserData $data): User
    {
        return User::create([
            'name' => $data->name,
            'username' => $data->username,
            'email' => $data->email,
            'password' => $data->password,
        ]);
    }
}
