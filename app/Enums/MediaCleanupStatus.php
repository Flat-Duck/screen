<?php

namespace App\Enums;

enum MediaCleanupStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
}
