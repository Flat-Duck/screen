<?php

namespace App\Enums;

enum CrashGroupStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
