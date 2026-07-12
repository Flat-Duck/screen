<?php

namespace App\Enums;

enum AccountPurgeOutcome: string
{
    case Purged = 'purged';
    case AlreadyGone = 'already_gone';
    case Busy = 'busy';
}
