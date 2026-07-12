<?php

namespace App\Enums;

enum PostPurgeOutcome: string
{
    case Purged = 'purged';
    case AlreadyGone = 'already_gone';
    case Busy = 'busy';
}
