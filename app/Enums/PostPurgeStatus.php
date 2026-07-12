<?php

namespace App\Enums;

enum PostPurgeStatus: string
{
    case Purging = 'purging';
    case Failed = 'failed';
}
