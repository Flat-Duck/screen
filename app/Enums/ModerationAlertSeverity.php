<?php

namespace App\Enums;

enum ModerationAlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /** Sort weight — lower sorts first, matching the queue ordering in ModerationCasesTable. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 1,
            self::Warning => 2,
            self::Info => 3,
        };
    }
}
