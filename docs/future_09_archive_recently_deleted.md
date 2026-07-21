# Archive and Recently Deleted Screenshots

Milestone 6.2 adds two private lifecycle states around a screenshot post. Archive is reversible
private storage. Recently Deleted is a time-limited recovery area before permanent media cleanup.
Neither state is a publishing surface.

## State model

```text
active --archive--> archived --unarchive--> active
   |                    |
   +------ delete ------+
              |
              v
       recently deleted --restore--> active
              |
              +-- permanent delete or retention expiry --> purged
```

Archiving sets `archived_at`. It does not soft-delete the row or remove saves and collection
memberships. Deleting clears the archive state and sets `deleted_at`. Restoration always returns
the screenshot to active state rather than silently re-archiving it.

## Mobile API contract

All endpoints require an authenticated user session and deliberately return `404` when the target
does not belong to the caller.

```text
POST   /api/v1/posts/{id}/archive
DELETE /api/v1/posts/{id}/archive
GET    /api/v1/archived-posts?cursor=...
GET    /api/v1/recently-deleted-posts?cursor=...
POST   /api/v1/posts/{id}/restore
DELETE /api/v1/posts/{id}/permanently-delete
```

Archive and unarchive are idempotent and return `204`. Both list endpoints use cursor pagination
and the standard `PostResource`. Archive results are newest-archived first; deletion results are
newest-deleted first.

The permanent-delete body follows the existing account step-up contract. Password accounts send
`current_password`. Accounts with two-factor authentication use the accepted two-factor method.
Passwordless accounts without 2FA first request an email code through the existing account
confirmation-code endpoint and then submit `confirmation_code`. Validation failures return `422`.

## Visibility and retention

Archived posts are excluded at the model-query layer from post detail, profiles, feeds, search,
recommendations, saved lists, and collection reads. They are removed from the search index when
archived and re-indexed when restored to active state. Moderation and account lifecycle services
explicitly bypass the archive scope where their authority requires it.

Recently Deleted lists only rows inside `social.post_retention_days` (30 by default) and excludes
posts belonging to an account-wide pending deletion. Restore returns `410` after that window and
`409` after cleanup has claimed the row. Scheduled pruning and explicit permanent deletion use the
same guarded media-cleanup action, so database rows are not force-deleted before storage cleanup
succeeds.

`PostResource` exposes nullable `archived_at`, `deleted_at`, and `scheduled_purge_at`. The purge
timestamp is derived from deletion time plus the configured retention period; it is not a promise
of exact execution time because the scheduler controls when cleanup runs.

## Operational requirements

The queue worker and scheduler must run in production. The scheduler invokes the existing deleted-
post pruning command; without `php artisan schedule:run`, expired screenshots remain stored until
an administrator or user initiates cleanup. Storage failures leave the post recoverably marked for
diagnosis rather than losing its database record.
