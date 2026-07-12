<?php

namespace App\Data\Maintenance;

final readonly class PruneSummary
{
    public function __construct(
        public int $purged = 0,
        public int $busy = 0,
        public int $alreadyGone = 0,
        public int $failed = 0,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
