# Private Saved Screenshot Collections

Milestone 6.1 turns the flat Saved list into an owner-only screenshot library. Collections are
private in v1 and are not searchable, shareable, recommended, or visible in the admin web app.

## Data contract

Collections contain a name, nullable description, zero-based position, fixed `private` visibility,
and integer version. Items contain one post, a nullable private note, zero-based position, and their
own integer version. A post may belong to several collections but has unique membership inside each
collection.

Adding an item ensures the post also exists in `saved_posts`. Removing an item or deleting a
collection leaves the general bookmark intact. Deleting the general bookmark removes that user's
membership from every collection and normalizes all affected positions.

## Ordering and concurrency

Collection creation and ordering lock the owning user/collection rows inside database transactions.
Moving or deleting an entry shifts surrounding positions and increments the versions of records
whose ordering changed.

Every PATCH requires the version returned by the last read. Item ordering mutations require both
the collection and item versions. Stale mutations return HTTP `409`; the mobile app must refresh and
reapply the user's intent. A repeated POST for an already-present item succeeds even with the
original version so network retries remain idempotent.

## Privacy and lifecycle

All collection queries include the authenticated owner ID and return `404` for another owner's ID.
Notes are returned only through collection endpoints and never enter search or recommendation
documents.

Collection listing cannot override post access. Current account visibility and block state are
rechecked before nesting a post in a response. An inaccessible or soft-deleted post is omitted while
its membership remains stored, allowing it to reappear if access is restored. A permanently deleted
post cascades its saved and collection memberships. Muting does not hide explicitly saved library
content because mute is defined as a feed/notification preference, not a block.

`items_count` describes stored membership and can therefore be greater than the number of currently
visible items returned by the posts endpoint.
