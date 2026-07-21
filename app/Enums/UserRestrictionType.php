<?php

namespace App\Enums;

enum UserRestrictionType: string
{
    case Posting = 'posting';
    case Commenting = 'commenting';
    case Messaging = 'messaging';
    case Recommendation = 'recommendation';
    case Login = 'login';
}
