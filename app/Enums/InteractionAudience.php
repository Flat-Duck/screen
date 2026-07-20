<?php

namespace App\Enums;

enum InteractionAudience: string
{
    case Everyone = 'everyone';
    case Followers = 'followers';
    case Following = 'following';
    case Mutuals = 'mutuals';
    case NoOne = 'no_one';
}
