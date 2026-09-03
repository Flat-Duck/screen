<?php

use App\Services\Moderation\Detectors\BrigadingDetector;
use App\Services\Moderation\Detectors\ReportSpikeDetector;
use App\Services\Moderation\Detectors\SlaBreachDetector;
use App\Services\Moderation\Detectors\TrendingTripwireDetector;

return [

    /*
    |--------------------------------------------------------------------------
    | Alert detection
    |--------------------------------------------------------------------------
    |
    | `moderation:detect-alerts` (scheduled every five minutes) runs each detector
    | below and raises a ModerationAlert when a threshold trips. Detection never
    | applies a consequence — every alert waits on a human, by design. Thresholds
    | are env-tunable so a noisy rule can be quietened without a deploy.
    |
    */

    'alerts' => [

        // Turn the whole detector run off without unscheduling it.
        'enabled' => (bool) env('MODERATION_ALERTS_ENABLED', true),

        /*
         * Detectors run in this order. Each must implement
         * App\Services\Moderation\Detectors\AlertDetector; the command resolves them
         * from the container, so a detector may inject whatever services it needs.
         */
        'detectors' => [
            ReportSpikeDetector::class,
            BrigadingDetector::class,
            SlaBreachDetector::class,
            TrendingTripwireDetector::class,
        ],

        /*
         * The trending tripwire raises an Info alert per ranked item, and a post that drops
         * out of the top-K simply stops being re-detected — nothing would ever close those,
         * so the open queue grew without bound. Stale Info alerts therefore expire on their
         * own. Only Info, and only while still Open: once a moderator acknowledges one it is
         * theirs, and Warning/Critical always wait for a person.
         */
        'stale_info' => [
            'expire' => (bool) env('MODERATION_EXPIRE_STALE_INFO', true),
            // Generous relative to the 5-minute cadence, so an item flickering around the
            // edge of the top-K is not expired and re-raised every other run.
            'grace_minutes' => (int) env('MODERATION_STALE_INFO_GRACE_MINUTES', 30),
        ],

        'report_spike' => [
            // Reports on one target inside the window before it counts as a spike.
            'threshold' => (int) env('MODERATION_REPORT_SPIKE_THRESHOLD', 5),
            'window_minutes' => (int) env('MODERATION_REPORT_SPIKE_WINDOW_MINUTES', 60),
            // At or above this, the alert is Critical rather than Warning.
            'critical_threshold' => (int) env('MODERATION_REPORT_SPIKE_CRITICAL', 15),
        ],

        'sla' => [
            /*
             * Hours a case may sit unresolved before it breaches, per priority. Urgent is
             * deliberately short enough that a breach means "somebody should be woken up",
             * not "we are mildly behind".
             */
            'hours' => [
                'urgent' => (int) env('MODERATION_SLA_URGENT_HOURS', 2),
                'high' => (int) env('MODERATION_SLA_HIGH_HOURS', 12),
                'normal' => (int) env('MODERATION_SLA_NORMAL_HOURS', 48),
                'low' => (int) env('MODERATION_SLA_LOW_HOURS', 168),
            ],
            // Multiple of the above at which a breach escalates from Warning to Critical.
            'critical_multiplier' => (float) env('MODERATION_SLA_CRITICAL_MULTIPLIER', 2.0),
            // Cap on alerts raised per run, oldest first — a cold-start backlog of 500 stale
            // cases should not bury every other alert type on the first run.
            'max_per_run' => (int) env('MODERATION_SLA_MAX_PER_RUN', 25),
        ],

        'trending_tripwire' => [
            // How deep into the ranking counts as "about to be seen by a lot of people".
            'post_top_k' => (int) env('MODERATION_TRIPWIRE_POST_TOP_K', 25),
            'tag_top_k' => (int) env('MODERATION_TRIPWIRE_TAG_TOP_K', 10),
            'tag_window_days' => (int) env('MODERATION_TRIPWIRE_TAG_WINDOW_DAYS', 7),

            /*
             * A ranked item alerts as Info on its own (the proactive half of the tripwire:
             * a human eyeballs what is about to go wide). It escalates when it also carries
             * signal: any reports at all, or a tag climbing this fast.
             */
            'reported_severity_threshold' => (int) env('MODERATION_TRIPWIRE_REPORT_THRESHOLD', 1),
            'tag_velocity_multiplier' => (float) env('MODERATION_TRIPWIRE_TAG_VELOCITY', 3.0),
            'tag_velocity_min_posts' => (int) env('MODERATION_TRIPWIRE_TAG_MIN_POSTS', 10),

            /*
             * Quiet mode: only alert on ranked items that already carry reports. Flip this on
             * if the proactive half proves too noisy in practice — the rule then still catches
             * "something bad is trending", just not until someone has reported it.
             */
            'only_when_reported' => (bool) env('MODERATION_TRIPWIRE_ONLY_WHEN_REPORTED', false),
        ],

        'brigading' => [
            'window_hours' => (int) env('MODERATION_BRIGADING_WINDOW_HOURS', 24),
            // One account filing this many reports across distinct targets in the window.
            'reporter_target_threshold' => (int) env('MODERATION_BRIGADING_REPORTER_TARGETS', 8),
            // Distinct accounts younger than `young_account_days` reporting one target.
            'young_reporter_threshold' => (int) env('MODERATION_BRIGADING_YOUNG_REPORTERS', 4),
            'young_account_days' => (int) env('MODERATION_BRIGADING_YOUNG_ACCOUNT_DAYS', 7),
        ],
    ],
];
