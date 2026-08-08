<?php

namespace App\Enums;

enum SecurityOutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';

    /** Terminal — set once DeliverSecurityOutboxMessage exhausts its attempts. Distinct from
     * Pending so DispatchPendingSecurityOutbox (which only re-picks-up Pending rows) naturally
     * stops re-dispatching it, instead of retrying an undeliverable message forever. */
    case Failed = 'failed';
}
