<?php

namespace App\Enums;

enum SecurityOutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
}
