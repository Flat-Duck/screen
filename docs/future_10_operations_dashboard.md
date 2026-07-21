# Operations Dashboard

Milestone 7.1 provides a bounded, admin-only view of production dependencies and asynchronous
workflows at `/operations`. It is separate from the product dashboard so operational access can be
restricted to super-admins and the telemetry-viewer role.

## Collection model

`operations:capture-health` runs every minute and writes one `operations_health_snapshots` row.
The web request only reads this snapshot; it does not contact Redis, storage, mail, or FCM. This
keeps page latency predictable and prevents page refreshes from creating dependency traffic.

Snapshots include status-only dependency results and bounded aggregate counts:

- database, Redis, writable media storage, mail configuration, and FCM configuration;
- backlog for the `default`, `security`, and `media` queues and failed jobs by queue over 24 hours;
- security-outbox, screenshot-processing, and cleanup failures;
- stored screenshot bytes and change from the nearest snapshot at least 24 hours old;
- the ten most-used app versions among devices active in the last 30 days.

Probe exceptions are reduced to `failed`; exception messages, paths, credentials, recipients, and
payloads are never stored in snapshots. `not_configured` is distinct from a failed configured
dependency. Snapshots are retained for 30 days and the UI marks the latest snapshot stale after
five minutes.

## Scheduler heartbeat

Laravel scheduled-task start, finish, and failure events update one durable row per command. The
dashboard shows the latest state, successful completion time, and runtime. It stores only the
exception class on failure, never its message or stack trace. An empty table means no scheduled
task has executed since this feature was deployed.

The deployment must invoke this every minute:

```text
* * * * * php artisan schedule:run
```

Queue workers must consume `default`, `security`, and `media`. Monitoring detects symptoms but does
not restart workers or retry failed jobs automatically.

## API measurement

The API middleware records one global minute bucket with request count, 5xx count, 429 count,
summed duration, and maximum duration. It deliberately does not record route, URL, query, body,
headers, token, user/device identity, or IP address. This provides a low-cardinality traffic and
latency signal without turning operations monitoring into another user-event store.

Buckets are retained for 30 days through the scheduled model-pruning command. The dashboard charts
the latest 60 minutes. For route-level percentiles and distributed traces, use an external APM;
this bounded internal signal is intended for outage detection rather than deep tracing.

## Access and incident use

The `operations.view` permission is granted to super-admins and telemetry viewers. Moderator,
support, auditor, ordinary user, and unauthenticated sessions cannot access the page. Responses use
the sensitive-page no-cache middleware.

During an incident, first verify snapshot freshness, then dependency state, queue backlog, scheduled
workflow completion, and API error/rate-limit counts. Horizon and Telescope remain the authorized
drill-down tools; the operations dashboard intentionally avoids sensitive raw details.
