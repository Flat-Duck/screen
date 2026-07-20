<?php

namespace App\Enums;

enum UserModerationState: string
{
    case Clear = 'clear';
    case Suspended = 'suspended';
    case Banned = 'banned';
}
