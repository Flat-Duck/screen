<?php

namespace App\Enums;

enum LoginMethod: string
{
    case Registration = 'registration';
    case Password = 'password';
    case Google = 'google';
    case Facebook = 'facebook';
    case Apple = 'apple';
}
