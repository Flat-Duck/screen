# Frontend Hand-off — New API Surface

## How to use this doc

This is a running log of backend changes the Android app can build against, kept up to date
after every feature ships (not just at the end of a roadmap phase). Each entry has the
endpoint(s), what changed in the response shape, and any behavior you need to know to wire
it up correctly. A **What's Next** section at the bottom always reflects the current
remaining backend roadmap so you can plan ahead — check it before starting new client work
in an area, in case something's about to change.

See `docs/mobile-app-plan.md` for the original screen-by-screen wiring plan and general
Android architecture guidance (auth/token storage, pagination, error handling, images). This
doc only covers what's changed *since* that plan was written — a few things below directly
correct claims made there (flagged inline).

---

## Shipped: 2026-07-19 — Safety & moderation + engagement depth

### 1. Block a user

- `POST /v1/users/{id}/block` — 204, idempotent.
- `DELETE /v1/users/{id}/block` — 204, idempotent.
- `GET /v1/blocked-users` — cursor-paginated `UserSummaryResource[]`.

Blocking auto-unfollows in both directions. `UserResource` (from `GET /v1/users/{id}`) gains
two new fields, both omitted when viewing your own profile (same `when()` pattern as
`is_following`):
- `is_blocked` — true if *you* blocked this user.
- `is_blocked_by` — true if *they* blocked you.

**Behavior to know:**
- A blocked-either-way profile/post/comment-list 404s (not 403) — the API deliberately
  doesn't reveal *which* party blocked whom. Treat a 404 on a previously-visible profile as
  "possibly blocked," not necessarily "deleted."
- Following, liking, or commenting on a blocked-either-way user's content returns 403.
- A blocked user's posts disappear from your feed even if you still show them as followed
  in stale local state — don't rely on client-side follow state to predict feed contents.

### 2. Mute a user

- `POST /v1/users/{id}/mute` — 204, idempotent.
- `DELETE /v1/users/{id}/mute` — 204, idempotent.
- `GET /v1/muted-users` — cursor-paginated `UserSummaryResource[]`.

Unlike Block, muting is invisible to the muted user and one-directional: they can still
follow/like/comment/message you normally. It only removes their posts from *your* feed and
suppresses notifications *you'd* get from them. There's no `is_muted` field on `UserResource`
— if you need to reflect mute state in a profile UI, check membership in the
`GET /v1/muted-users` list client-side (it's expected to stay small).

### 3. Post editing

- `PATCH /v1/posts/{id}` — body: `{"caption": "..."}` or `{"caption": null}` to clear it.
  **Omitting `caption` entirely leaves it unchanged** — don't send `{}` expecting a no-op
  clear; you must explicitly send `null` to clear. Returns the full updated `PostResource`.
- Caption-only — there's still no way to add/remove/reorder images after posting.
- New `edited_at` field on `PostResource` (`null` if never edited) — show an "edited"
  indicator when non-null, same convention you'd expect from any edited-content UI.
- Editing re-parses hashtags and `@mentions` from the new caption (see below).

### 4. Comment threading (replies)

- `POST /v1/posts/{id}/comments` — body now accepts an optional `parent_id`. Must be the ID
  of an existing **top-level** comment on the same post — replying to a reply is rejected
  (422 on `parent_id`) — **one level of nesting only**.
- `GET /v1/comments/{id}/replies` — cursor-paginated replies to a top-level comment.
- `GET /v1/posts/{id}/comments` **now returns only top-level comments** — this is a behavior
  change from before. Each comment includes a `replies_count`; fetch the replies endpoint
  lazily (e.g. "View N replies" affordance) rather than expecting them inline.
- `CommentResource` gains: `parent_id` (null for top-level), `replies_count`.
- Deleting a top-level comment cascades and deletes its replies too.

### 5. Comment likes

- `POST /v1/comments/{id}/like` / `DELETE /v1/comments/{id}/like` — same shape as post likes
  (`{"likes_count": N}` response).
- `CommentResource` gains: `likes_count`, `is_liked` (bool, viewer-specific, same convention
  as `PostResource.is_liked`).
- **This directly resolves the "Drop per-comment like button" note in
  `docs/mobile-app-plan.md` (Screenshot Detail section) and the "Comment likes + threaded
  replies" Phase 2 backlog item — both are built now, not deferred.**

### 6. @mentions

- No new endpoints — `@username` in a post caption or comment body is now parsed
  automatically on create/edit. Only mentions of a username that actually exists are
  recorded; unknown `@handles` are silently ignored (no error).
- New notification type, same shape/delivery as existing ones (`GET /v1/notifications`):
  `data.mentionable_type` (`"post"` or `"comment"`), `data.mentionable_id`,
  `data.mentioner_id`, `data.mentioner_username`, `data.excerpt`.
- New settings key: `notifications.mentions` (boolean, default `true`) via the existing
  `GET`/`PATCH /v1/settings` endpoints — add a toggle for it alongside the existing
  likes/comments/follows ones.
- No client-side action needed to "create" a mention beyond just typing `@username` in text
  you're already sending — `GET /v1/search/users?q=` (see the 2026-07-19 Search entry below)
  now exists if you want to add `@`-autocomplete while typing.

### 7. Saved / bookmarked posts

- `POST /v1/posts/{id}/save` / `DELETE /v1/posts/{id}/save` — 204, idempotent.
- `GET /v1/saved-posts` — cursor-paginated `PostResource[]`, always *your own* saved posts
  (no `{user_id}` param — this list is private, there's no way to view anyone else's).
- `PostResource` gains `is_saved` (bool, viewer-specific) — present on every post-returning
  endpoint: feed, post detail, user's posts, and saved-posts itself.
- **This resolves part of the "Liked/Collections tabs" gap noted in
  `docs/mobile-app-plan.md`'s User Profile section** — a "Saved" tab can now be built against
  `GET /v1/saved-posts`. (A "Liked posts" tab specifically — i.e. `GET /v1/users/{id}/liked-
  posts` — is still not built; that was a separate, smaller Phase 2 backlog item and remains
  open, see What's Next.)

---

## Shipped: 2026-07-19 (later same day) — Search

### 8. Search

Search results are relevance/page paginated through Laravel Scout. Use the standard Laravel
`meta.current_page`, `meta.last_page`, and `links` values; search endpoints no longer promise a
cursor because relevance is a computed order rather than a stable ID order.

- `GET /v1/search/users?q=` — page-paginated `UserSummaryResource[]`, matches
  username/name using Scout database search. Excludes: inactive accounts, yourself, and anyone blocked
  either-way (silently — they just don't appear in results, no error).
- `GET /v1/search/posts?q=` — page-paginated `PostResource[]`, relevance-ranked against the
  screenshot search document (caption today; OCR/category/source fields can be added later).
- `GET /v1/search/hashtags?q=` — page-paginated response with `name`/`posts_count`/
  `is_followed` per hashtag (not a full `PostResource`-style object — hashtags have no `id`
  exposed, only `name`, since that's how they're referenced everywhere else in the API too,
  including the new browse endpoints below).
- Results use Scout's database engine. Usernames and hashtags use prefix search; screenshot posts
  use full-text relevance in PostgreSQL. Tests use Scout's collection engine.
- Separate, tighter rate limit than most reads: 20/min per user (vs. 60/min for `reads`).
- `q` is required, 1–100 chars — a `422` on `q` if omitted/empty, same validation-error
  shape as everywhere else in the API.

## Shipped: 2026-07-20 — Private accounts and follow requests

- Read/update visibility at `data.privacy.account_visibility` (`public` or `private`) through
  `GET/PATCH /v1/settings`.
- `POST /v1/users/{user}/follow` returns `204` for a public account, or `202` with
  `{"data":{"status":"requested","request_id":123}}` for a private account.
- `DELETE /v1/users/{user}/follow` unfollows or cancels a pending request idempotently.
- Pending queues: `GET /v1/follow-requests/incoming` and `/outgoing`.
- Respond with `POST /v1/follow-requests/{id}/accept` or `/decline`.
- Public profile metadata remains visible. Private posts, reposts, follower/following lists,
  hashtag content, search results, saved posts, and direct interaction URLs require ownership or
  an accepted follow. Private posts never enter Explore/discovery.
- `UserResource` now includes `account_visibility`, `follows_you`, and
  `follow_request_status` (`pending` or `null`) for relationship-aware buttons.

## Shipped: 2026-07-20 — Interaction permissions

- `GET/PATCH /v1/settings` now includes `interactions.comments_from`, `mentions_from`,
  `messages_from`, `reposts_from`, and `reposts_allowed`.
- Audience values are `everyone`, `followers`, `following`, `mutuals`, and `no_one`.
  `followers` means the actor follows the account receiving the interaction; `following` means
  that receiving account follows the actor.
- Defaults remain permissive (`everyone`, with reposts enabled) so current mobile clients retain
  their behavior until they expose these controls.
- Post create/update accepts `comments_enabled` and `reposts_enabled`; both default to `true` and
  are returned by `PostResource`.
- Disallowed comment/repost/message writes return `403`. Starting a disallowed conversation
  returns the existing `422 user_id` validation shape. Disallowed mentions remain visible as
  ordinary caption/comment text but create no mention record or notification.
- Disabling comments blocks new comments and replies but leaves existing threads readable.
  Disabling reposts blocks new reposts without deleting historical reposts.

## Shipped: 2026-07-20 — Message requests

- `POST /v1/conversations` accepts optional `initial_message` (maximum 1,000 characters).
  Allowed contacts still receive an `active` conversation. A contact outside the recipient's
  `messages_from` audience must provide `initial_message` and receives a `requested` conversation.
  `messages_from=no_one` rejects the operation with a `422 user_id` error.
- `GET /v1/message-requests` returns incoming requests only, cursor paginated and separate from
  `GET /v1/conversations`. Each item includes `state`, `requested_by`, and `latest_message`.
- Accept with `POST /v1/conversations/{id}/accept`; reject with
  `POST /v1/conversations/{id}/reject`. Only the receiving participant may respond.
- Requested conversations allow message history viewing but reject additional sends and read
  receipt updates with `409`. Acceptance moves the thread into both users' primary inbox.
- A rejection prevents another request for 30 days by default. Configure this with
  `SOCIAL_MESSAGE_REQUEST_REJECTION_COOLDOWN_DAYS`.
- `DELETE /v1/conversations/{id}` hides the thread only for the caller. A later active message
  makes it visible to its recipient again.
- Report with `POST /v1/conversations/{id}/report` using `reason` and optional `details`.
- Blocking rejects pending requests, hides them from the blocker, and prevents all further sends.
- Notification settings now include `notifications.message_requests`.

## Shipped: 2026-07-20 — Hidden words and notification controls

- Hidden-term endpoints: `GET /v1/hidden-terms`, `POST /v1/hidden-terms` with `value` and optional
  `type` (`word` or `phrase`), and `DELETE /v1/hidden-terms/{id}`. Lists are cursor paginated;
  values are limited to 100 characters and accounts to 100 terms.
- Matching is case-insensitive, Unicode-normalized, and folds common punctuation/number evasion.
  Original term values are encrypted at rest and never placed in logs or filter-match records.
- Matching comments/messages remain stored but return `body: null` and `is_filtered: true` only
  for the user whose filter matched. Other authorized participants see the original with
  `is_filtered: false`. Removing a term removes its associated redactions.
- `content_filters.hide_offensive_comments` and `hide_offensive_messages` enable the deployment's
  policy-reviewed `SOCIAL_OFFENSIVE_TERMS` lexicon.
- Notification settings now include `push_enabled`, `replies`, `product_updates`, and
  `quiet_hours` (`enabled`, `start`, `end`, `timezone`) in addition to the existing categories.
  Times use `HH:mm`; timezone must be an IANA timezone. Quiet hours suppress push only—database
  notifications remain available. Account-security push types bypass social toggles and quiet
  hours.

## Admin-only: 2026-07-20 — Moderation cases and content browser

- Mobile report contracts are unchanged. New reports are automatically grouped by target into an
  open moderation case; duplicate reports by one reporter remain idempotent.
- Admin routes: `/moderation/cases`, `/moderation/cases/{id}`, `/moderation/content`, and
  `/moderation/content/{post}`. They require `moderation.view`; mutations additionally require
  `moderation.manage`.
- Cases support assignment, priority, internal notes, investigating/actioned/dismissed transitions,
  warnings, suspension/ban, content removal/restoration, and recommendation exclusion.
- Private and soft-deleted screenshots remain available through an authenticated, no-store media
  preview route. Captions and report details are rendered escaped, never as raw HTML.
- Recommendation-ineligible posts are excluded from trending refresh, discovery injection, and
  Explore even if stale IDs remain in Redis.

## Admin-only: 2026-07-20 — User detail and scoped restrictions

- `/users/{id}` now provides account/moderation state, social and connected-account summaries,
  recent screenshots, devices, sessions, reports, warnings, restrictions, audit history, and
  internal support notes. The page is permission-gated and returned with no-store headers.
- Restriction types are `posting`, `commenting`, `messaging`, `recommendation`, and `login`.
  They may be scheduled, temporary, permanent, overlapping, extended, or revoked. Expiry is
  evaluated from timestamps at authorization time and therefore needs no cleanup job to become
  effective.
- Restricted API writes return `403`; reading existing comments/messages remains available.
  Recommendation restrictions affect only Explore/discovery, not followers' chronological feed.
  Login restrictions reject new sessions and immediately revoke existing sessions/tokens.
- Restriction creation, extension, revocation, and support notes are audited with the acting admin
  and reason. Admins cannot apply restrictions to themselves through this workflow.

### 9. Hashtag browse pages + follow

- `GET /v1/hashtags/{name}` — single hashtag: `name`, `posts_count`, `is_followed`. Route
  param is the tag text itself (no leading `#` needed, case-insensitive — `bug`, `Bug`, and
  `#bug` all resolve the same row). 404 if no post has ever used that tag.
- `GET /v1/hashtags/{name}/posts` — cursor-paginated `PostResource[]` for that tag, newest
  first, excludes blocked-either-way authors same as everywhere else.
- `POST /v1/hashtags/{name}/follow` / `DELETE .../follow` — 204, idempotent.
- `GET /v1/hashtags/followed` — cursor-paginated, always *your own* followed hashtags.
- **Following a hashtag is bookmark-only in v1** — it does not notify you when a new post
  uses that tag, and followed-tag posts are not blended into your main feed. If you build a
  "Following" UI section for tags, treat it the same as the Saved-posts list: something the
  user visits deliberately, not a passive feed source.
- One route-naming gotcha to be aware of (server-side, doesn't affect your calls, just
  worth knowing): a hashtag literally named `followed` would be unreachable via
  `GET /v1/hashtags/followed` — that path always resolves to "my followed hashtags," never a
  tag called `#followed`. Extremely unlikely to matter in practice.

### 10. Explore (standalone discovery feed)

- `GET /v1/explore?page=` — this finally unblocks `docs/mobile-app-plan.md`'s
  `discover_grid_light` screen. Same `PostResource[]` shape as everywhere else
  (`is_liked`/`is_saved` included), ranked by the same trending algorithm that already
  powers the feed's inline discovery splice.
- **Paginated by `page` number, not `cursor`** — the one list endpoint in the whole API that
  works this way. Use `links.next`/`meta` from the response the same way you would for any
  offset-paginated API (page number in the query string), not the cursor-based
  `PagingSource` pattern you're using for `feed`/`comments`/`followers`/etc. This is
  intentional, not an inconsistency to work around: the ranked set lives in a Redis sorted
  set with no stable orderable column to build a cursor from.
- Unlike the feed's inline discovery splice, Explore **does** include posts from accounts
  you already follow — it's a "what's popular right now" surface, not specifically an
  out-of-network one. Only your own posts and blocked-either-way authors are excluded.
- Empty/degraded Redis just yields an empty page (no error) — same fail-open behavior as the
  feed's discovery splice, so handle it as "nothing trending right now," not a failure state.

---

## Shipped: 2026-07-19 (Phase 4, part 1) — Repost/sharing

### 11. Repost

- `POST /v1/posts/{id}/repost` — body: optional `{"comment": "..."}` for a quote-repost.
  204, idempotent (reposting something already reposted is a no-op — it does **not** update
  a previously-set comment). `422` if you try to repost your own post.
- `DELETE /v1/posts/{id}/repost` — 204, idempotent.
- `GET /v1/users/{id}/reposts` — cursor-paginated, but **not** a `PostResource[]` like other
  post lists. Each item is a repost *event*: `{"id", "comment", "post": PostResource, "created_at"}`
  — the wrapped `post` has the usual `is_liked`/`is_saved`/counts already annotated.
- **v1 is profile-only — reposts are never blended into anyone's home feed.** They only show
  up via this dedicated endpoint on the reposting user's own profile. `GET /v1/users/{id}/posts`
  is unaffected — a repost never appears there. If you build a "Reposts" tab on User Profile,
  point it at this new endpoint, separate from "Latest Snapshots."
- New notification type (`type: "repost"`) and settings key `notifications.reposts`
  (default `true`), same conventions as every other notification type.

---

## Shipped: 2026-07-19 (Phase 4, part 2) — Direct Messages

This is the last item on the current backend roadmap. Everything from the original feature
audit has now shipped.

### 12. Direct Messages

- `POST /v1/conversations` — body: `{"user_id": <id>}`. Idempotent find-or-create — starting
  a conversation with someone you already have a thread with just returns that same thread
  (check `data.id` against a local cache before assuming it's new). `422` if `user_id` is
  yourself or a blocked-either-way user.
- `GET /v1/conversations` — cursor-paginated, always your own conversations, newest activity
  first. Each item: `{"id", "other_participant": UserSummaryResource, "last_message_at", "unread"}`.
  **1:1 only** — `other_participant` is always exactly one user, there's no group-chat
  concept in this API (the schema underneath is group-ready for a future version, but v1's
  API surface only ever creates/returns 2-participant threads — don't build group UI against
  this).
- `PATCH /v1/conversations/{id}/read` — 204. Updates only *your* read marker; the other
  participant's `unread` state is unaffected.
- `GET /v1/conversations/{id}/messages` — **two different modes depending on whether you
  pass `after`:**
  - No `after` param → cursor-paginated, newest-first, same convention as every other list
    endpoint. Use this for loading conversation history (initial open, scroll-back).
  - `?after=<message_id>` → a flat array (not cursor-paginated — no `links`/`meta`) of
    messages newer than that id, oldest-first, capped at 100. Use this for polling while a
    thread is open: keep the highest message id you've seen, poll this periodically (a
    reasonable interval needs your own UX judgment — not specified server-side), and append
    results in order.
- `POST /v1/conversations/{id}/messages` — body: `{"body": "..."}`, max 2200 chars. Returns
  the created `MessageResource`. **`403` if the other participant has since blocked you (or
  you blocked them)** — but the conversation and its history remain fully viewable via `GET
  .../messages` even after that; only sending becomes forbidden. Distinct, tighter-than-
  `writes-moderate` rate limit: 60 requests/min.
- **No message edit or delete in v1.** No media attachments — text only.
- **No realtime socket connection.** This app has no WebSocket/Reverb infra. Delivery is:
  poll `GET .../messages?after=` while a thread is actively open on screen, and rely on the
  existing FCM push channel (new notification type `"message"`, settings key
  `notifications.messages`, default `true`) to wake the client when backgrounded — the exact
  same pattern every other notification type in this API already uses, nothing new to learn
  there.
- **No message-request/approval gate.** Any two users who aren't blocked-either-way can
  start a conversation directly — there's no "message request" holding area for strangers
  like some platforms have. This was an explicit v1 scope call, flagged as a likely v1.1
  addition if abuse becomes a problem in practice; don't build a "pending requests" UI for
  it now, there's nothing on the backend for it to point at.

---

## Corrections to `docs/mobile-app-plan.md`

The following claims in that doc are now outdated as of this hand-off:
- Line 56 ("Drop: per-comment like button... and Reply — no backing endpoints") — both now
  exist, see items 4 and 5 above.
- Line 122 ("Comment likes + threaded replies: would need a `comment_likes` table/endpoints
  and a `parent_id` on `comments`") — built exactly as described; remove from backlog.
- Line 74 ("'Collections' and 'Liked' tabs → no backing endpoint... 'Liked' in particular is
  a small addition later") — "Saved" (not "Liked") now has a backing endpoint; see item 7.
  "Liked posts" specifically is still open.

---

## What's Next

All milestones in the current backend roadmap have shipped. The next phase is mobile integration
against the OpenAPI contract, a staging load test, and production-readiness drills before launch.

---

## Shipped: 2026-07-21 — private saved collections (Milestone 6.1)

- `GET /v1/collections` lists the authenticated user's collections in zero-based `position` order.
- `POST /v1/collections` accepts `name` (required, 100 characters), nullable `description` (500),
  and optional `visibility: "private"`. Public/shared collections are not supported.
- `PATCH /v1/collections/{id}` updates `name`, `description`, or `position` and requires the current
  integer `version`.
- `DELETE /v1/collections/{id}` requires `{"version": N}`. Deleting a collection does not globally
  unsave its screenshots.
- `GET /v1/collections/{id}/posts` cursor-paginates collection items. Each item contains private
  `note`, zero-based `position`, `version`, and a nested `PostResource`. The top-level `collection`
  object provides the current collection version.
- `POST /v1/collections/{id}/posts/{post}` requires `collection_version`; optional fields are
  `note` and `position`. It automatically adds the screenshot to the general Saved list. Repeating
  an existing add is idempotent and returns `200`; a new membership returns `201`.
- `PATCH /v1/collections/{id}/posts/{post}` requires both `collection_version` and item `version`,
  and updates `note` and/or `position`.
- `DELETE /v1/collections/{id}/posts/{post}` requires both versions. Removing one membership does
  not globally unsave the screenshot.
- A stale version returns `409`; refetch the collection/items and retry the user's intended change.
- Collections are owner-only. Access to another user's collection returns `404`.
- One screenshot may belong to multiple collections, but only once per collection. Globally
  un-saving it removes all of the acting user's collection memberships.
- Inaccessible screenshots are omitted without tombstones while membership is retained. They can
  reappear if private-account access returns. Permanent post deletion cascades membership removal.

---

## Shipped: 2026-07-21 — archive and recently deleted (Milestone 6.2)

- `POST /v1/posts/{id}/archive` privately archives an owned screenshot; repeating it is safe.
- `DELETE /v1/posts/{id}/archive` restores an owned archived screenshot; repeating it is safe.
- `GET /v1/archived-posts` cursor-paginates only the authenticated user's archived screenshots.
- Archived screenshots disappear from all public/profile/feed/search/recommendation and saved-
  collection reads. Saved and collection memberships remain stored.
- The existing `DELETE /v1/posts/{id}` moves an active or archived screenshot into Recently
  Deleted and keeps its media during the retention window.
- `GET /v1/recently-deleted-posts` cursor-paginates owned screenshots still inside the configured
  retention window (30 days by default). `deleted_at` and `scheduled_purge_at` are included.
- `POST /v1/posts/{id}/restore` restores an eligible screenshot as active content. It returns `410`
  after the retention window and `409` once permanent cleanup has begun.
- `DELETE /v1/posts/{id}/permanently-delete` permanently removes the row and media. It uses the
  same step-up contract as account deletion: current password, TOTP recovery flow, or emailed
  confirmation code depending on the account. It returns `409` if cleanup is already running.
- Every endpoint is owner-only and uses `404` for another user's or unavailable screenshot ID.
- `PostResource` now always exposes nullable `archived_at`, `deleted_at`, and
  `scheduled_purge_at`; normal active responses contain nulls for these fields.

---

## Shipped: 2026-07-21 — operations dashboard (Milestone 7.1)

- `/operations` is an admin-web page available only to super-admins and telemetry viewers. It is
  intentionally not a mobile API and stores/displays no credentials or raw exception messages.
- `operations:capture-health` records database, Redis, media-storage, mail, and FCM state plus
  queue/failed-job counts, media and cleanup failures, security-mail backlog, screenshot storage,
  and 30-day app-version adoption.
- The scheduler runs that capture every minute. Snapshots older than five minutes are visibly
  marked stale and snapshot history is retained for 30 days.
- Every scheduled command records its latest start, success/failure, and runtime so a missing
  scheduler and stalled workflows are distinguishable.
- `/api/*` traffic is aggregated into minute buckets containing counts, 5xx errors, 429 responses,
  total latency, and maximum latency. No URL, body, token, user ID, IP address, or headers are
  retained. Buckets are pruned after 30 days.
- Production must run `php artisan schedule:run` every minute and queue workers for `default`,
  `security`, and `media`; otherwise the dashboard will correctly become stale or show backlogs.

---

## Shipped: 2026-07-21 — crash-group triage (Milestone 7.2)

- `/crash-groups` groups fatal and non-fatal errors by the existing redacted stable fingerprint.
  It is an admin-web workflow, not a mobile endpoint.
- Groups retain status, assignment, notes, counts, first/last seen time, and fixed app version even
  after raw telemetry expires under its normal retention policy.
- The list supports status, app release, Android OS, device manufacturer/model, exception, name,
  and fingerprint filters.
- Detail pages show a 14-day occurrence chart, filtered occurrence count, and ten recent sample
  events linking to existing raw-event inspection.
- Telemetry viewers may read triage data. Only super-admins may assign/unassign, add internal notes,
  investigate, resolve, ignore, or reopen groups. Every mutation requires a reason and is audited.
- Valid states are `open`, `investigating`, `resolved`, and `ignored`. Resolved or ignored groups
  can be reopened; reopening clears the previous fixed-version value.
- Event ingestion updates groups idempotently. A retry repairs an event left ungrouped by a
  transient failure without increasing occurrence or affected-user counts twice.

---

## Shipped: 2026-07-21 — contracts, load tests, and runbooks (Milestone 7.3)

- `docs/openapi-v1.json` is the OpenAPI 3.1 contract for every registered mobile route. It records
  public/device/user authentication boundaries and reusable request/response models.
- Run `php artisan api:export-contract` after an intentional API change. `composer test` runs
  `api:export-contract --check` and fails if routes and the committed document have drifted.
- Contract tests validate route/method completeness, `$ref` integrity, unique operation IDs,
  backend-required request fields, and real `PostResource`/`UserResource` payloads.
- `load/k6/mobile-api.js` covers feed, Explore, search, notifications, screenshot upload,
  messaging, analytics ingestion, and device telemetry. Mutating scenarios require explicit
  environment variables and must run only in an authorized staging/disposable environment.
- Runbooks now cover database/media backup and restore drills, queue/scheduler outages, moderation
  escalation, account compromise, and deletion incidents under `docs/runbooks/`.

---

## Shipped: 2026-07-20 — recommendation feedback (Milestone 5.3)

- `POST /v1/posts/{post}/not-interested` hides a post from that user's recommendation candidates;
  `DELETE` the same endpoint to undo it. Both operations are idempotent.
- `POST /v1/posts/{post}/hide` permanently hides the post from that user's recommendation
  candidates in v1. There is intentionally no restore endpoint for Hide yet.
- `POST /v1/users/{user}/show-fewer` applies a negative ranking signal to that author's future
  candidates. It is not a block, mute, or complete exclusion.
- `POST /v1/hashtags/{hashtag}/show-fewer` applies the equivalent topic penalty. Hashtags use their
  normalized name in the route, as with existing hashtag endpoints.
- `DELETE /v1/recommendations/profile` clears post feedback, author/topic show-fewer state, affinity
  rows, raw recommendation interaction events, and outstanding For You sessions. It does not delete
  the account, login/device sessions, security history, posts, saves, follows, blocks, or messages.
- Every feedback mutation invalidates outstanding For You snapshots for the acting user. Start the
  next request without the previous cursor; an invalidated cursor returns `422`.
- Feedback is user-local. Administrative exclusions are the only feedback controls that affect all
  users.
- Administrators can disable For You globally. During a shutdown, `/feed/for-you` returns an empty
  valid response, including for existing cursors; `/feed/following` continues normally.

---

## Shipped: 2026-07-20 — personalized feeds (Milestone 5.2)

- `GET /v1/feed/following` is the explicit reverse-chronological feed of followed accounts. It uses
  the existing Laravel cursor pagination and does not contain recommendation metadata.
- `GET /v1/feed/for-you` is the personalized feed. It accepts `per_page` from 1–30 and an opaque
  `cursor`; never inspect, construct, or persist assumptions about the cursor format.
- A first For You request creates a short-lived stable feed session. Subsequent cursors page through
  that immutable ranking without duplicates or cursor drift. Starting without a cursor creates a
  fresh ranking.
- The For You response adds `meta.feed_session_id`, `meta.request_id`, `meta.next_cursor`, and
  `meta.has_more`. Each post adds:

```json
{
  "recommendation": {
    "request_id": "7bc1d3d0-...",
    "source": "followed_hashtag",
    "reason": "Related to a hashtag you follow"
  }
}
```

- Send that `request_id`, `source` as `candidate_source`, position, and `surface: "for_you_feed"`
  with Milestone 4.1 interaction events. Reasons are display-safe server text; clients may show them
  directly but should not branch product logic on the wording.
- A cursor returns `422` if malformed, expired, or used by another user. On that response, discard it
  and start a new For You request without a cursor.
- Hard safety/privacy changes are rechecked on every page. A blocked, moderated, or newly private
  item may therefore disappear from an existing session; this policy takes priority over filling
  every requested page slot.
- `GET /v1/feed` remains the legacy compatibility endpoint during migration.

---

## Shipped: 2026-07-20 — recommendation candidate generation (Milestone 5.1)

- This is an internal recommendation-pipeline layer; existing feed endpoints and response shapes do
  not change yet.
- The server now builds bounded candidate pools from following, hashtags, categories, global and
  country trending, two-hop follows, author/topic affinities, and new-creator exploration.
- Privacy, account visibility, blocks, mutes, recommendation restrictions, moderation eligibility,
  screenshot safety, and prior negative feedback are applied before candidates reach ranking.
- Candidate records carry their source, source-local score, generation time, and eligibility
  metadata. Duplicate posts retain the first source plus additional-source provenance.
- Milestone 5.2 will consume these pools and introduce the mobile-facing For You contract. Do not
  infer or reproduce these candidate rules on the client.

---

## Shipped: 2026-07-20 — feature flags and experiments (Milestone 4.3)

- `GET /v1/feature-configuration` returns `data.flags` and
  `data.experiment_assignments` for the authenticated user.
- Flags are keyed by their canonical string key. Each enabled flag contains `version` and a
  server-defined `payload`. A missing flag is off for that user; do not apply a local rollout.
- `GET /v1/feed` now includes a top-level `experiment_assignments` object. Cache it with the feed
  page and echo it on related Milestone 4.1 interaction events.
- The server validates reported assignments against assignments it previously issued to that user.
  Forged or locally selected variants return `422`.
- Assignments are deterministic and sticky within an experiment version. Version changes may issue
  a new assignment; previously issued versions remain valid for delayed/offline event uploads.
- Start/end windows and kill switches are enforced by the server. Clients should treat absent flags
  and assignments as disabled and must not retain them past the latest configuration response.
- Privacy, moderation, safety, authentication, and visibility behavior are excluded from
  experimentation by policy and validation.

---

## Shipped: 2026-07-20 — screenshot accessibility and context (Milestone 3.1)

- `GET /v1/screenshot-categories` returns active options in display order as
  `{id, slug, name}[]`.
- `POST /v1/posts` accepts optional `media_metadata`, with exactly one object per uploaded image
  and in the same order: `media_metadata[0][alt_text]`, etc. Alt text is nullable and limited to
  1,000 characters.
- Post create/update accepts nullable `category_id`, `source_application` (100 characters),
  `source_url` (2,048 characters; public HTTP/HTTPS only), and `content_warning`
  (`sensitive` or `spoiler`).
- `PATCH /v1/posts/{post}/media/{media}` updates the owner's alt text using
  `{"alt_text": "Description"}`; send `null` to clear it. The image itself is unchanged.
- `PostResource` adds `category`, `source_application`, `source_url`, and `content_warning`.
  `PostMediaResource` adds `alt_text` and `safety_status`.
- OCR text, OCR metadata, and perceptual hashes are deliberately private server fields and are
  not returned by the API.

---

## Shipped: 2026-07-20 — screenshot processing (Milestone 3.2)

- OCR, duplicate hashing, and sensitive-information evaluation now run asynchronously for every
  uploaded screenshot. This does not delay post creation or image availability.
- `media[].safety_status` now resolves from `pending` to `clear`, `warning`, or `failed`.
  `warning` means the client should display a generic caution; the API intentionally supplies no
  detected text or secret details.
- OCR and duplicate data remain internal. OCR does not affect search results yet, and no new OCR
  or duplicate fields were added to the mobile response.
- Operational requirement: media workers need Tesseract installed, or
  `SOCIAL_OCR_BINARY` configured to a compatible executable. Language defaults to `eng` and can
  be changed with `SOCIAL_OCR_LANGUAGE`.

---

## Shipped: 2026-07-20 — pre-publication safety flow (Milestone 3.3)

Use this flow for all new mobile post creation; `POST /v1/posts` remains temporarily available only
so older clients do not break.

1. `POST /v1/media/analyses` as multipart with `images[]` and optional position-aligned
   `media_metadata[][alt_text]`. Returns `202` with `{token, status, expires_at,
   requires_acknowledgement, items}`. Tokens expire after 30 minutes.
2. If `status` is `processing`, poll `GET /v1/media/analyses/{token}`. Each item returns only
   `position`, processing `status`, `safety_status`, and findings shaped as
   `{category, region: {x, y, width, height}}`. Coordinates are normalized from 0 to 1. No
   detected text is returned.
3. On a warning, either redact locally and cancel/re-upload, cancel with
   `DELETE /v1/media/analyses/{token}`, or explicitly continue.
4. Publish with `POST /v1/media/analyses/{token}/publish`, sending the normal caption/category/
   source/content-warning fields. If `requires_acknowledgement` is true, also send
   `acknowledge_sensitive: true`; otherwise the API returns `422`. Publishing returns the normal
   `PostResource` with `201` and consumes the token.

Tokens are private to their creator: another user receives `404`. Expired tokens return `410`,
unfinished analyses return `409` on publish, and abandoned files are removed automatically.

---

## Shipped: 2026-07-20 — content analytics ingestion (Milestone 4.1)

`POST /v1/analytics/content-events` accepts `{events: [...]}` with 1–50 events and returns
`{accepted_event_ids: [...]}`. Retry the same UUID safely: duplicates are idempotent. Batch the
events periodically rather than sending one request per impression. The body is capped at 256 KB
and the endpoint at 30 batches/minute.

Every event requires `event_id` (UUID), `event_type`, `author_id`, `surface`, and `occurred_at`.
Post-based events also require `post_id`. Optional common fields are `position` (0–999),
`candidate_source`, `request_id` (UUID), and up to 20 `experiment_assignments`. Never send user,
device, or session IDs; the server derives them from the active mobile token.

Allowed event types: `impression`, `open`, `carousel_swipe`, `zoom`, `dwell`, `like`, `comment`,
`save`, `collection_add`, `repost`, `share`, `profile_open`, `follow_author`, `hide`,
`not_interested`, and `report`. Only `profile_open` and `follow_author` omit `post_id`.

Allowed surfaces: `following_feed`, `for_you_feed`, `explore`, `search`, `hashtag`, `profile`,
`post_detail`, `saved`, `notification`, and `share_sheet`. Allowed candidate sources are
`following`, `trending`, `followed_hashtag`, `category`, `two_hop`, `similar_author`,
`similar_topic`, `new_creator`, `search`, `profile`, `direct`, and `notification`.

Event-specific metadata is deliberately narrow:

- `dwell`: `duration_ms` (0–600,000)
- `carousel_swipe`: `media_position` (0–9) and `direction` (`next`/`previous`)
- `zoom`: `media_position`
- `share`: `share_channel` (`system`, `copy_link`, or `external`)
- `hide`/`not_interested`: `reason` (`not_relevant`, `seen_before`, `low_quality`, `sensitive`,
  or `other`)

Analytics events do not perform the action they describe. Continue calling the real like, save,
follow, report, comment, and repost endpoints; analytics cannot change their counters or state.
Events older than 30 days, more than five minutes in the future, before the current device session,
for inaccessible posts, or with mismatched authors are rejected. Raw rows are retained for 90 days.
