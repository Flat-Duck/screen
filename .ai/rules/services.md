---
paths:
  - app/Services/OperationsHealthService.php
---

# Services

## Health snapshots prune inline, not via the scheduler
`operations:capture-health` runs every minute (1440 rows/day), but `OperationsHealthSnapshot` is deliberately NOT Prunable and is absent from the `model:prune` list in routes/console.php. Retention is enforced inline in `OperationsHealthService::capture()`, which deletes rows older than 30 days right after inserting. Don't "fix" the missing prune schedule — the table is already bounded at ~43k rows. It is still the largest table in the database by a wide margin, so if storage pressure appears, shorten the inline window rather than adding a second prune path.
