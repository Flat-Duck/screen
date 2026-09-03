<?php

namespace App\Enums;

enum ModerationAlertType: string
{
    /** N reports landed on one target inside a short window. */
    case ReportSpike = 'report_spike';

    /** A case has sat open past the threshold for its priority. */
    case SlaBreach = 'sla_breach';

    /** A tag or post entered the top-K ranking — fires whether or not it has been reported. */
    case TrendingTripwire = 'trending_tripwire';

    /** One reporter hitting many targets, or many young accounts hitting one target. */
    case Brigading = 'brigading';

    public function label(): string
    {
        return match ($this) {
            self::ReportSpike => 'Report spike',
            self::SlaBreach => 'SLA breach',
            self::TrendingTripwire => 'Trending tripwire',
            self::Brigading => 'Coordinated reporting',
        };
    }
}
