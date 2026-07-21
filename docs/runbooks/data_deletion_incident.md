# Account or Screenshot Deletion Incident

Use this when deletion is stuck, media cleanup fails, content reappears, the wrong scope may have
been deleted, or retention deadlines are at risk.

1. Stop automated/manual retries if scope is uncertain. Record account/post IDs, request time,
   retention deadline, purge status, cleanup task ID, storage disk, and application version. Never
   paste media, credentials, or full user profiles into the incident ticket.
2. Determine state without changing it: active, archived, recently deleted, account-deletion hold,
   purging, failed, or already gone. Check queue/scheduler health and the audit trail.
3. If cleanup failed, repair the dependency first. Retry the established purge action in a bounded
   batch; it deletes media before force-deleting database rows and is safe to re-enter. Do not
   manually force-delete rows while media deletion is unverified.
4. If content reappeared, disable the affected read/recommendation path, preserve evidence of the
   visibility failure, and verify archive/soft-delete global scopes, Scout removal, caches, replicas,
   collections, and recommendation pools.
5. If excessive/wrong deletion may have occurred, freeze further cleanup, preserve logs, invoke the
   backup/restore drill procedure in isolation, and involve privacy/security leadership. Restoration
   must respect user intent, moderation decisions, and legal holds.
6. Verify completion across primary DB, replicas, media storage/versioning, search, caches, saved and
   collection joins, telemetry attribution, and backups according to documented expiry—not merely an
   API `404`.

Communicate affected scope, whether data remains recoverable, containment, regulatory/user notice
decision, and expected completion. Close only with evidence that deletion invariants and deadlines
are satisfied and prevention work is tracked.
