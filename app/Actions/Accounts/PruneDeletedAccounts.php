<?php

namespace App\Actions\Accounts;

use App\Data\Maintenance\PruneSummary;
use App\Enums\AccountPurgeOutcome;
use App\Models\User;
use DateTimeInterface;
use Throwable;

final class PruneDeletedAccounts
{
    public function __construct(private readonly PurgeDeletedAccount $purgeAccount) {}

    public function __invoke(DateTimeInterface $cutoff): PruneSummary
    {
        $purged = $busy = $alreadyGone = $failed = 0;

        foreach (User::onlyTrashed()->where('deleted_at', '<', $cutoff)->select('id')->lazyById(100) as $candidate) {
            try {
                $outcome = ($this->purgeAccount)($candidate->id);
                $purged += $outcome === AccountPurgeOutcome::Purged ? 1 : 0;
                $busy += $outcome === AccountPurgeOutcome::Busy ? 1 : 0;
                $alreadyGone += $outcome === AccountPurgeOutcome::AlreadyGone ? 1 : 0;
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return new PruneSummary($purged, $busy, $alreadyGone, $failed);
    }
}
