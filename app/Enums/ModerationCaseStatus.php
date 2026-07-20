<?php

namespace App\Enums;

enum ModerationCaseStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
