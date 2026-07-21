# Feature Flags and Experiments

Milestone 4.3 provides server-owned rollout controls for product, recommendation, and operational
changes. It is intentionally not a general remote-settings system: privacy, moderation, safety,
authentication, and visibility behavior cannot be placed behind flags or experiments.

## Mobile contract

Authenticated clients fetch `GET /api/v1/feature-configuration` at session start and when returning
to the foreground. The response contains:

```json
{
  "data": {
    "flags": {
      "feed.new_header": {"version": 1, "payload": {"style": "compact"}}
    },
    "experiment_assignments": {"feed_density": "treatment"}
  }
}
```

Missing keys are disabled. The client must never calculate rollout buckets or choose variants.
Feed responses repeat `experiment_assignments`; interaction events should echo the assignments from
the feed that caused the interaction. The API accepts historical assignments issued to that user so
queued offline events survive experiment version changes.

## Evaluation rules

- Eligibility is evaluated server-side using enabled state, UTC start/end windows, and kill switch.
- Rollout and experiment allocation use stable HMAC buckets from 0–9,999.
- Experiment variants must include explicit `control` and `treatment` entries and total 10,000 basis
  points. Additional named variants are allowed.
- Assignments are persisted per user and experiment version. Configuration changes increment the
  version; the experiment salt stays stable unless explicitly changed.
- Kill switches immediately omit the flag or experiment from new responses without deleting
  historical assignments.

## Administration

`GET /experiments` is the initial read-only admin view. It shows lifecycle state, allocation,
versions, assignment totals, and variant counts. Configuration is deliberately restricted to
audited commands whose actor must be a super administrator and whose reason is mandatory:

```bash
php artisan features:configure feed.new_header admin@example.com --enable --rollout=25 --reason="Canary rollout"
php artisan experiments:configure feed_density admin@example.com --enable --allocation=20 --variants=control:50,treatment:50 --reason="Ranking trial"
php artisan experiments:configure feed_density admin@example.com --kill --reason="Emergency stop"
```

Percent options are converted to basis points. Allowed scopes are `product`, `recommendation`, and
`operations`. Every successful change writes before/after snapshots to the admin audit log.
