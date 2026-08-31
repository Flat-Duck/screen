# Neon production-clone migration drill

Use this before every production schema release. A Neon child branch is an isolated copy-on-write
clone, so migrations and rollback can be exercised against production-like schema and data without
writing to the production branch. Follow Neon's current
[branching guidance](https://neon.com/docs/guides/branching-intro).

## Preconditions

- Name an operator and reviewer; record the application commit and UTC start time.
- Confirm the workspace is linked to the intended Neon project and identify the protected
  production branch. Never infer the parent from whichever branch happens to be selected.
- Disable outbound mail, FCM, queues, schedules, and search indexing for the drill application.
- Do not paste connection strings, passwords, or production data into tickets, logs, or chat.
- If production is protected, expect the child branch to receive different role passwords.

## Create the isolated branch

Use the Neon Console or authenticated CLI to create a child of the production branch named
`test/migration-<commit>-<UTC timestamp>`. Pull or copy that child branch's **pooled** and **direct**
connection details into an ephemeral secret file outside the repository.

Configure the drill application:

```dotenv
APP_ENV=staging
DB_CONNECTION=pgsql
DB_HOST=<child pooled hostname containing -pooler>
DB_DIRECT_HOST=<child direct hostname without -pooler>
```

Use the child branch's database, role, password, port, and `sslmode=require` for both connections.
Never point either variable at production during the drill.

## Deploy and verify

```bash
PHP_BIN="$(command -v php)" deploy/database.sh status
PHP_BIN="$(command -v php)" deploy/database.sh migrate
PHP_BIN="$(command -v php)" deploy/database.sh status
php artisan test
php artisan api:export-contract --check
```

Run staging smoke tests for authentication, posting, private saves, telemetry ingestion, queues, and
admin reads. Compare the child with its parent using Neon schema diff. Review table rewrites, lock
duration, index builds, row counts, and application compatibility before approving production.

## Rollback exercise

Only if the release's `down()` migrations are reviewed as non-destructive:

```bash
PHP_BIN="$(command -v php)" deploy/database.sh rollback --step=1
PHP_BIN="$(command -v php)" deploy/database.sh status
PHP_BIN="$(command -v php)" deploy/database.sh migrate
```

Repeat smoke tests after rollback and after re-migration. If rollback is unsafe, rehearse restoration
by creating a fresh branch from the recorded pre-migration point and verify the application against
that branch instead.

## Evidence and cleanup

Record branch ID/name, parent, commit, migration batch, preflight output, durations, schema diff,
smoke-test results, rollback/restore result, reviewer, and any follow-up owner. Delete the temporary
branch and ephemeral credentials only after the reviewer accepts the evidence.

## Drill record — 2026-08-28

- Project: `Akukas App`, Postgres 18, Frankfurt (`aws-eu-central-1`).
- Parent: `production`; isolated child: `test/migration-production-readiness-20260828`, configured
  to expire automatically on 2026-08-30.
- Cached-config preflight: passed; `pgsql_direct` resolved to the unpooled child endpoint and
  connectivity succeeded without logging credentials.
- Pooled runtime check: Laravel's default `pgsql` connection completed a read through the child
  branch's `-pooler` endpoint.
- Deploy: `2026_08_28_000001_add_source_disk_to_private_saves_table` applied in 716.96 ms.
- Rollback: the same migration rolled back in 471.04 ms.
- Recovery/reapply: the migration reapplied in 498.48 ms.
- Schema diff after reapply: exactly one nullable `private_saves.source_disk varchar(255)` column;
  no unrelated schema drift.
- Follow-up: the production branch reported `protected=false`; enable Neon branch protection before
  the production release.
