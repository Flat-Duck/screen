<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\IssuedAccessToken;

class RegisterUser
{
    public function __construct(private readonly IssueAccessToken $issueToken) {}

    /** @param array<string, string> $data */
    public function __invoke(array $data): IssuedAccessToken
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return ($this->issueToken)($user, $data['device_name'] ?? 'mobile');
    }
}
