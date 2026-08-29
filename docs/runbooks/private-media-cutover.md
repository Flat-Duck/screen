# Private media cutover

All user-created post media, private saves, avatars, and group photos are stored in a private R2
bucket. API responses do not expose object keys or permanent object-store URLs. They return
twenty-minute, viewer-bound signed application URLs; the delivery endpoint rechecks current access
before streaming the object.

## Access policy

| Media state | Who can receive and use a signed URL | Cache policy |
|---|---|---|
| Active post by a public account | Any API-authenticated viewer who is not blocked | Public cache, at most the signed URL lifetime |
| Active post by a private account | Owner and current followers who are not blocked | `private, no-store` |
| Archived or soft-deleted post | Owner only | `private, no-store` |
| Private save | Owner only | `private, no-store` |
| Public-account avatar | Authenticated viewers who are not blocked | Public cache, at most the signed URL lifetime |
| Private-account avatar | Authenticated viewers who are not blocked | `private, no-store` |
| Public group photo | Authenticated viewers | Public cache, at most the signed URL lifetime |
| Private group photo | Current group members | `private, no-store` |
| Purged post/private save | Nobody; object and database row are removed | Not applicable |

Absolute external URLs exist only in synthetic seed data and are inherently public. User upload
paths are always storage keys and are never returned directly.

## First production cutover

1. Back up the R2 bucket and database. Record an example old permanent media URL and several post
   and private-save IDs for verification.
2. Confirm every object referenced by `post_media.source_disk = r2` and legacy
   `private_saves.source_disk IS NULL` exists in `R2_BUCKET`.
3. Set `SOCIAL_MEDIA_DISK=r2`, `SOCIAL_UPLOADS_DISK=r2`, and
   `SOCIAL_PRIVATE_MEDIA_DISK=r2`. Do not configure `R2_PUBLIC_URL`.
4. Deploy the signed-media code and run the database migration. While the bucket is still public,
   verify API media fields use `/media/posts/...`, `/media/private-saves/...`, `/media/avatars/...`,
   or `/media/groups/...` signed URLs and do not contain an R2 hostname or object path.
5. If all legacy private-save objects are on R2, set their explicit source disk through the direct
   database connection:

   ```sql
   UPDATE private_saves SET source_disk = 'r2' WHERE source_disk IS NULL;
   ```

   If any row points to another disk, copy and size-verify its object first, update only that row,
   and delete the source object only after a successful read through the signed delivery route.
6. Disable R2 public-development access and detach any public custom domain. Keep S3 API access
   available only to the application's scoped credentials.
7. Purge cached objects from the former public hostname/CDN.
8. Verify the recorded old permanent URL now returns an anonymous-access denial while newly issued
   signed URLs still render.
9. Verify a block, public-to-private account change, archive, and soft deletion make a previously
   issued non-owner URL return 404. Verify expired and modified signatures return 403.

Do not reopen the bucket as a rollback. If application delivery fails after privacy is enabled,
roll back the application release while keeping the bucket private, then diagnose with an
authenticated R2 client.

## How the bytes actually move

The delivery endpoints authorize every request, then choose one of two paths:

- **Presigning remote disk (production R2):** the endpoint returns a `302` to a presigned object
  URL and Cloudflare/R2 transfers the bytes. Nothing large passes through PHP-FPM.
- **Local disk (development, tests):** the endpoint streams the file itself. Local disks are
  excluded from offloading even when they can sign URLs, because a redirect would point back at
  this same application and save nothing.

This matters for capacity, not correctness: streaming holds an FPM worker for the whole transfer,
which is almost entirely network wait, so one scrolling feed can occupy the worker pool of a small
app server while doing no real work. Signed capability URLs are viewer-bound, so a shared CDN
cache cannot deduplicate them between users — without offloading, every image for every viewer
would be paid for twice in origin bandwidth (R2 to app server, app server to client).

The redirect is never `public`-cacheable: its `Location` header is a bearer capability for the
object. Media that was already uncacheable stays `no-store` so every view is reauthorized.

Set `SOCIAL_MEDIA_OFFLOAD_ENABLED=false` to force streaming everywhere without a code change.

## Operational limits

- `SOCIAL_MEDIA_URL_TTL_SECONDS` controls the signed capability lifetime; production defaults to
  1,200 seconds so it safely outlives Android's 15-minute API-response cache.
- `SOCIAL_MEDIA_OFFLOAD_TTL_SECONDS` (default 120) is the lifetime of the presigned object URL the
  redirect hands out. It is deliberately much shorter than the capability URL: the capability is
  reauthorized on every use, the presigned URL is not, so this value is the window in which a
  viewer who has just lost access can still fetch that one object. Lower it to tighten revocation
  at the cost of more redirects; do not raise it above the capability lifetime.
- `SOCIAL_PUBLIC_MEDIA_CACHE_SECONDS` must not exceed the signed URL lifetime.
- Public responses can be cached only until the capability expires. Restricted media and private
  saves are always `no-store`.
- Changing access cannot erase bytes already downloaded by a viewer, but every uncached request is
  reauthorized and capability leakage is bounded by the short expiry.
