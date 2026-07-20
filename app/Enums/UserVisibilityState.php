<?php

namespace App\Enums;

enum UserVisibilityState: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
}
