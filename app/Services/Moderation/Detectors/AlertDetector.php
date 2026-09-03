<?php

namespace App\Services\Moderation\Detectors;

use App\Services\Moderation\AlertDraft;

/**
 * Registered in config('moderation.alerts.detectors') and resolved from the container, so a
 * detector may inject services freely. A detector reads and emits — it must never mutate
 * content, cases or users.
 */
interface AlertDetector
{
    /** Short identifier used in command output and failure logging. */
    public function name(): string;

    /** @return iterable<int, AlertDraft> */
    public function detect(): iterable;
}
