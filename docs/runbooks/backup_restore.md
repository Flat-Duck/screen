# Database and Media Backup/Restore Drill

## Preconditions

- Name an incident commander and record the drill ticket, environment, expected RPO, and RTO.
- Use a disposable restore environment isolated from production FCM, mail, queues, and search.
- Confirm encryption-key and credential backups are available through the secret manager. Never put
  keys, database dumps, or user media in tickets or chat.
- Pause application writes or use a database snapshot plus object-storage version at the same
  recovery point. Database and media backups must be treated as one recovery set.

## Backup

1. Record database engine/version, schema migration head, application commit, UTC start time, and
   the media bucket/version marker.
2. Create an encrypted database snapshot using the managed database backup facility. Prefer a
   storage-provider snapshot/versioned replication for screenshot media rather than enumerating
   objects through the application.
3. Export checksums and object/database counts into the protected backup system; do not copy raw
   user data into the repository.
4. Verify the snapshot is restorable and retention/immutability policies match the recovery policy.

## Restore drill

1. Provision isolated database, Redis, and media storage. Disable outbound mail and FCM.
2. Restore the database and media recovery set, configure a non-production application instance,
   then run `php artisan migrate --force` only if the application commit is newer than the snapshot.
3. Run `php artisan about`, `php artisan migrate:status`, `php artisan api:export-contract --check`,
   and the smoke tests. Start one worker per queue only after dependencies are verified.
4. Sample active, archived, recently deleted, and permanently purged screenshot records. Confirm
   expected media exists for retained rows and purged content does not reappear.
5. Verify user/session counts, latest telemetry timestamp, security-outbox state, crash groups,
   analytics aggregates, and search reconciliation. Rebuild Scout indexes if the search snapshot is
   outside the recovery point.
6. Record achieved RPO/RTO, discrepancies, owners, and deadlines. Destroy the isolated restore and
   its secrets under the temporary-environment policy.

Never declare success from a dump file alone; the drill succeeds only after application-level reads,
media access, authentication isolation, and deletion invariants are verified.
