<?php

namespace App\Data\Auth;

final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $username,
        public string $email,
        public string $password,
    ) {}
}
