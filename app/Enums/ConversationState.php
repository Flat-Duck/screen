<?php

namespace App\Enums;

enum ConversationState: string
{
    case Active = 'active';
    case Requested = 'requested';
    case Rejected = 'rejected';
}
