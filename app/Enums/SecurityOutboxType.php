<?php

namespace App\Enums;

enum SecurityOutboxType: string
{
    case ChangeEmailVerification = 'change_email_verification';
    case EmailChangedNotification = 'email_changed_notification';
}
