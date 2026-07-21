# Telemetry Crash-Group Triage

Milestone 7.2 turns individual error telemetry into a durable engineering workflow. It uses the
existing server-generated, redacted crash fingerprint; raw messages and stack traces do not become
group keys or group metadata.

## Grouping and durability

Every new telemetry error or fatal crash with a fingerprint is linked to one `crash_groups` row.
The group stores a display name, exception class, first/last seen timestamps, occurrence count, and
unique affected-user count. Anonymous crashes contribute occurrences but not affected users.

`crash_group_users` provides exact unique-user counting without storing user identifiers in the
group summary. Duplicate device event UUIDs remain idempotent and do not increment group counts.
Grouping also runs on retries, allowing an already-persisted but temporarily ungrouped event to
self-heal. Existing fingerprinted telemetry is backfilled by the migration.

Raw telemetry continues to follow `TELEMETRY_RETENTION_DAYS`. Deleting an old raw event does not
delete its crash group, counts, assignment, fixed version, or notes. Sample availability therefore
shrinks with retention while engineering history remains durable.

## Admin workflow

`/crash-groups` and `/crash-groups/{id}` require `telemetry.view`. List filters include workflow
status, release, Android OS version, device manufacturer/model, name, exception class, and
fingerprint. The detail page applies release/OS/device filters consistently to its 14-day chart,
filtered count, and ten newest representative events.

Statuses and allowed transitions are:

```text
open -> investigating | resolved | ignored
investigating -> open | resolved | ignored
resolved | ignored -> open
```

Assignment automatically moves an open group to investigating. Resolving may record a fixed app
version. Reopening clears resolution time and fixed version so stale fix metadata is not presented
as current.

## Authorization and audit

Telemetry viewers have read-only access. Mutations require `telemetry.manage`, currently restricted
to super-admins. Assignment targets must themselves have telemetry access. Assignment, unassignment,
notes, resolve, ignore, investigate, and reopen operations require a meaningful reason and produce
an `admin_audit_logs` entry. Notes are capped at 5,000 characters and are internal only.

Crash detail and raw-event pages remain sensitive operational data. They are session-authenticated;
the triage detail response also receives no-cache and no-index headers. This milestone adds no
mobile API and exposes no telemetry data to end users.
