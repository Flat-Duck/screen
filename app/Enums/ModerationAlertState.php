<?php

namespace App\Enums;

enum ModerationAlertState: string
{
    /** Nobody has looked at it. Counts toward the sidebar badge. */
    case Open = 'open';

    /** A moderator has seen it and is on it. Drops off the badge, stays in the queue. */
    case Acknowledged = 'acknowledged';

    /** Dealt with. Frees the open_key so the same condition can alert again later. */
    case Resolved = 'resolved';
}
