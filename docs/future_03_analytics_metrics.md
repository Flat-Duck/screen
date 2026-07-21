# Analytics Metric Definitions

## Time and rebuild policy

All aggregation boundaries are UTC `[00:00:00, next day 00:00:00)`. Dashboard dates may be
localized for display, but records and cohort day numbers never use an administrator's timezone.
The current UTC day is marked `is_partial=true`; completed days are false.

`php artisan analytics:aggregate` rebuilds yesterday. Use `--date=YYYY-MM-DD` for one day or
`--from=YYYY-MM-DD --to=YYYY-MM-DD` for an inclusive repair/backfill of up to 366 days. Rebuilding
deletes that day's derived rows and recreates them in one transaction, so reruns and late events
do not double count. The scheduler rebuilds today hourly and yesterday daily at 00:15.

## Product metrics

- **DAU:** distinct users with at least one accepted content event whose `occurred_at` is in the
  UTC day.
- **WAU:** distinct users represented in `daily_user_activity` over the latest seven UTC dates.
- **Registrations:** user rows created during the UTC day, including accounts subsequently soft
  deleted but not accounts already permanently purged.
- **Active creators:** distinct authors who created a post during the UTC day.
- **Screenshots published:** `post_media` images belonging to posts created during the UTC day.
- **Impression-to-X rate:** accepted X events divided by accepted impression events across the
  latest seven UTC dates. A zero-impression denominator returns 0%, never infinity.
- **Hide rate:** hide plus not-interested events divided by impressions.
- **Report rate:** report events divided by impressions.
- **Crash-free sessions:** sessions started during the metric day with no fatal-crash telemetry,
  divided by all sessions started that day. Non-fatal caught errors do not make a session crashed.
- **Moderation backlog:** current open plus investigating cases. Oldest age is measured from the
  oldest such case's creation time and is a live metric, not a daily aggregate.

Analytics interaction events remain behavioral claims and never replace authoritative likes,
saves, follows, comments, reposts, or reports.

## Retention cohorts

A cohort is users registered on a UTC date. `day_number` is the whole-day distance between
`cohort_date` and `activity_date`. A retained user has at least one accepted content event on the
activity date. The job materializes day 0 through day 30 for each activity date. Current-day
cohort rows are partial.

## Exposure and feedback aggregates

- Daily user activity stores event count, unique posts, exposure/open counts, bounded dwell
  milliseconds, positive actions, and negative-feedback actions.
- Daily post metrics store unique viewers and per-post exposure/interaction/negative counts.
- Recommendation feedback groups by UTC date, surface, and candidate source and stores unique
  users plus impressions, opens, saves, follows, hides, not-interested events, and reports.

## Explainable affinity weights

User-author and user-category affinity is materialized per UTC day. Self-author affinity is
excluded. Impression is neutral; the score weights are:

| Event | Weight |
|---|---:|
| Open | +1 |
| Dwell | +1 per 10 seconds, capped at +3 |
| Profile open | +2 |
| Like | +3 |
| Comment / share | +4 |
| Save / repost | +5 |
| Follow author | +6 |
| Hide | -5 |
| Not interested | -6 |
| Report | -10 |

These are explainable features for later ranking work, not a production ranking formula by
themselves. Eligibility, privacy, safety, diversity, and manipulation controls must still run
before any affinity is used.
