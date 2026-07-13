<?php

namespace App\Enums;

enum SessionEndReason: string
{
    case Logout = 'logout';
    case Replaced = 'replaced';
    case Revoked = 'revoked';
    case EmailChanged = 'email_changed';
    case AccountDeleted = 'account_deleted';
    case Expired = 'expired';
}
