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

- `dwell`: `duration_ms` (0–600,000) — see the 2026-08-08 entry below for two more optional fields
- `carousel_swipe`: `media_position` (0–9) and `direction` (`next`/`previous`)
- `zoom`: `media_position`
- `share`: `share_channel` (`system`, `copy_link`, `external`, or `group` — see 2026-08-08 below)
- `hide`/`not_interested`: `reason` (`not_relevant`, `seen_before`, `low_quality`, `sensitive`,
  or `other`)

Analytics events do not perform the action they describe. Continue calling the real like, save,
follow, report, comment, and repost endpoints; analytics cannot change their counters or state.
Events older than 30 days, more than five minutes in the future, before the current device session,
for inaccessible posts, or with mismatched authors are rejected. Raw rows are retained for 90 days.

---

## Shipped: 2026-08-01 — real `reposts_count` on `PostResource`

`PostResource` gains `reposts_count` — the total number of times the post has been reposted
(same shape/convention as `likes_count`/`comments_count`: a plain integer, always present,
never null). It's populated everywhere a post is returned (feed, post detail, saved posts,
saved collections, library/archive, search, hashtags, groups, explore/trending, and the
`post` embedded inside `RepostResource`), so any client-side estimate or placeholder for a
repost count can be replaced with this field directly.

There is still no `is_reposted` — that remains intentionally out of scope for v1 (see the
"Repost" section above): reposts are profile-only and the feed's repost action never needs
to know the viewer's own repost state for a given post.

---

## Shipped: 2026-08-02 — group photos, discoverability, invites, trending hashtags, and Explore filters

Groups themselves (`GET/POST /v1/groups`, membership, group feed, share-into-group) shipped
earlier (2026-07-26) but never got a write-up here — this entry covers both that base shape
and today's additions together.

### 13. Group cover photo + discoverability

- `POST /v1/groups` now accepts two more optional fields alongside `name`/`description`/
  `visibility`: `photo` (multipart image — `jpeg`/`png`/`webp`, ≤5MB, ≥100×100px, same
  validation shape as `PATCH /v1/profile`'s `avatar`) and `is_discoverable` (boolean,
  default `true`). Send `multipart/form-data` if you're including `photo`; plain JSON still
  works for text-only fields.
- `is_discoverable` is a **separate dimension from `visibility`**, not a public/private
  synonym — a private group can still choose whether it's findable in search once someone
  has a direct link/invite. There's no server-side search-ranking behavior wired to it yet
  (nothing currently reads it to filter/boost discovery results); it's stored and returned
  so the client has somewhere real to persist the toggle, same spirit as other fields that
  exist ahead of the UI that will consume them.
- `GroupResource` gains `is_discoverable` (boolean) and `photo_url` (nullable string) — present on
  every group response (index/show/store), `null` when no photo was ever uploaded. `photo_url`,
  like user avatars and post media, is now a short-lived signed URL rather than a permanent storage
  URL; refresh the containing resource after expiry.
- No dedicated "update group photo later" endpoint yet — `photo` is create-time only, same
  scope as the Android client's own create-group form.

### 14. Group invites

New relationship type, same "pending row, updateOrCreate, accept/decline flips its status"
shape `follow-requests` already uses, just scoped to `(group, invitee)` instead of
`(requester, target)`:

- `POST /v1/groups/{group}/invites` — body `{"user_id": <int>}`. **Admin-only**: 403 if the
  caller isn't that group's admin. 422 if the target user is already a member. Idempotent
  per `(group, invitee)` — inviting an already-pending invitee just refreshes the row (new
  inviter, fresh `pending` status) rather than erroring or duplicating. Returns `202` with a
  `GroupInviteResource`.
- `GET /v1/group-invites/incoming` — the current user's own pending invites, cursor-paginated,
  newest first. This is the only invite-listing endpoint that exists — there's no "invites I
  sent" (outgoing) list yet, unlike follow-requests which has both directions.
- `POST /v1/group-invites/{groupInvite}/accept` — 204. Only the invitee can accept (404
  otherwise); 409 if the invite is no longer `pending`. Joins the group (increments
  `member_count`, creates the `group_members` row with `role: member`) and marks the invite
  `accepted` in one transaction.
- `POST /v1/group-invites/{groupInvite}/decline` — 204. Same ownership/pending checks as
  accept; no membership side effect.
- `GroupInviteResource` shape: `{id, status, group: GroupResource, inviter: UserSummaryResource,
  created_at}`. `status` is one of `pending`/`accepted`/`declined`/`cancelled` (the last is
  reserved for parity with follow-requests' enum shape — nothing currently transitions an
  invite to `cancelled`, there's no revoke-by-inviter endpoint yet).

### 15. Trending hashtags

- `GET /v1/hashtags/trending?limit=&days=` — new, **global** (not per-viewer) ranked list.
  `limit` defaults to 10 (max 50), `days` defaults to 7 (max 90) and controls the activity
  window used for ranking only. Returns `HashtagResource[]` (`name`, `posts_count`,
  `is_followed` for the calling viewer) — `posts_count` is each hashtag's real **all-time**
  total (`withCount('posts')`, same as `hashtags/followed`), not the windowed count; the
  window only decides *which* tags make the list, not the number shown next to them.
- Ranking source is real activity (count of posts tagged with each hashtag inside the
  window), restricted to posts from publicly-visible accounts and not archived — same
  "public accounts, not the full per-viewer follow graph" baseline `GET /v1/explore` already
  uses for its own non-personalized candidate set. This is deliberately *not* per-viewer —
  trending is the same list for everyone, unlike a personalized feed.
- Registered ahead of the `GET /v1/hashtags/{hashtag}` wildcard route, same reason
  `hashtags/followed` already is — otherwise `GET /v1/hashtags/trending` would resolve as
  "look up a hashtag literally named `trending`".
- **Backend bug fixed in the same pass, not something new to build around:** `hashtag_post`
  pivot rows never had `created_at`/`updated_at` populated before today (`Post::hashtags()`/
  `Hashtag::posts()` were missing `->withTimestamps()`) — nothing read that column until this
  endpoint needed it to rank by recency. Existing rows were backfilled from their post's own
  `created_at`; new rows self-populate correctly now. No client-visible contract change, just
  flagging it in case any historical `hashtag_post.created_at` value you may have cached
  looks suspiciously identical to a migration run timestamp rather than the real tag date —
  that's the backfill, not a data-quality bug on your end.

### Explore filters

`GET /v1/explore` (see section 10 above) gains two optional query params, applied on top of
the existing Redis-ranked candidate batch:

- `category` — a `ScreenshotCategory` slug (see `GET /v1/screenshot-categories` for the
  list). Filters to posts whose `category_id` matches.
- `country` — a 2-letter ISO 3166-1 alpha-2 code, case-insensitive, same format rule as
  `PATCH /v1/profile`'s `country_code` (not validated against the full ISO list server-side).
  Filters to posts whose author's `country_code` matches.
- Neither filter changes the pagination contract or guarantees a full page of filtered
  results — they narrow the same fixed-size batch of Redis-ranked IDs the unfiltered query
  already fetches per page, the same honesty the existing visibility/eligibility/block/mute
  exclusions already have (a page can already come back thinner than `perPage` today). A
  wider, iterative Redis over-fetch to backfill a filtered page to full would be a real
  pagination-architecture change, out of scope here.

### 16. Notification actor avatars

Every notification type's `data` payload (`GET /v1/notifications`) now includes the acting
user's avatar URL alongside their existing id/username fields — `liker_avatar_url`,
`follower_avatar_url`, `commenter_avatar_url`, `mentioner_avatar_url`, `replier_avatar_url`,
`reposter_avatar_url`, `sender_avatar_url` (new message), `requester_avatar_url` (follow
request received), `avatar_url` (follow request accepted — that one class didn't already
namespace its other fields per-actor, so this one isn't prefixed either, matching its
existing `user_id`/`username` keys). Same nullable-string shape as `UserResource.avatar_url`
elsewhere — `null` when that user has no avatar set, never a placeholder URL. Added so a
notifications feed can render the actor's real photo instead of a generic icon; no endpoint
signature changed, this is purely new keys inside the existing untyped `data` map.

### 17. Removed: `POST`/`DELETE /v1/users/{user}/follow-requests`

These two `FollowRequestController` routes are gone — they were never anything other than a
second, redundant entry point to the exact same action `POST`/`DELETE /v1/users/{user}/follow`
(`FollowController::store`/`destroy`) already perform: both call the same
`FollowRequestService::request()`/`cancel()` under the hood, and `FollowController::store`
already returns the `202`-with-`request_id` shape for a private account. If any client was
calling the `/follow-requests` variant directly, switch it to `/follow` — behavior is identical.
`GET /v1/follow-requests/incoming`/`/outgoing` and `POST /v1/follow-requests/{id}/accept`/
`/decline` are unaffected and still live.

## Shipped: 2026-08-02 — the composer only sends the image and the words now

The mobile "post to timeline" flow (`POST /v1/media/analyses` → poll `GET .../{token}` → `POST
.../{token}/publish`) picks category, content warning, and alt text server-side now. The client's
job shrank to: pick an image, write a caption (hashtags and all), optionally pick a destination
group, send.

- **`POST /v1/media/analyses`** no longer accepts `media_metadata`/`alt_text` at all — there's no
  OCR result yet at upload time, so there was never anything meaningful to seed it with. Just
  `images[]`.
- **`GET /v1/media/analyses/{token}`** (poll) — each item in `data.items` gains
  `suggested_alt_text`: OCR text, whitespace-collapsed, capped at 1000 chars. `null` while still
  processing, once OCR found no text at all, *or* whenever that item's own `safety_status` is
  `"warning"` — deliberately never auto-suggesting alt text sourced from the same text a warning
  exists to keep from being echoed back. Pre-fill the composer's (still-editable) alt-text field
  with this once the item is `"ready"`.
- **`POST /v1/media/analyses/{token}/publish`** — `category_id` and `content_warning` are no
  longer accepted fields; sending them is silently ignored (not a `422`, just absent from
  `validated()`). Both are computed:
  - `category_id`: `App\Services\Screenshots\CategoryMatcher` scores every active
    `screenshot_categories` row's `keywords` against the caption's `#hashtags` (weight 3) and the
    OCR'd text's words (weight 1), picks the top scorer if it clears a minimum of 3, else leaves
    the post uncategorized (`null`) — a v1 keyword heuristic, not ML, by design (see the class's
    own kdoc for the reasoning). `GET /v1/screenshot-categories` is unaffected and still exists —
    it's still used elsewhere (e.g. the Explore category filter), just no longer surfaced as a
    manual picker in the composer.
  - `content_warning`: `"sensitive"` whenever any media item's `safety_status` came back
    `"warning"` (the same `SensitiveInformationAnalyzer`/PII-detection pass that already gated the
    `acknowledge_sensitive` confirmation step — that step is unchanged, still required before a
    "warning" analysis can publish), `null` otherwise. There is no automatic path to `"spoiler"` —
    that value is retired; nothing about OCR'd text can indicate a narrative spoiler, so it was
    never anything but a manual label, and the composer no longer offers one.
  - New optional field: **`alt_text`** (string, max 1000) — overrides the OCR suggestion for a
    single-image analysis; ignored (each item just keeps its own OCR suggestion) if the analysis
    has more than one image, since a single override can't disambiguate which one it was meant
    for.
  - New optional field: **`group_id`** — posts directly into that group in the same request,
    instead of the old "publish to timeline, then separately `POST
    /v1/groups/{group}/posts/{post}` to re-share it" two-step flow. `403` if the caller isn't a
    member of that group, and atomically — a rejected group post creates no timeline post either,
    nothing is left half-published. The re-share endpoint itself is untouched and still the way to
    additionally share an *already-published* post into a second group afterward.

---

## Shipped: 2026-08-08 — richer dwell signal + `group` as a share channel

Two additions to the `dwell` event's `metadata` (see the Milestone 4.1 entry above for the full
content-events contract) — both optional, so existing clients that only send `duration_ms` are
unaffected:

- **`completion_rate`** (0–1) — the largest fraction of the post's own on-screen height the client
  ever displayed during that dwell. Meaningful mainly for a tall screenshot the viewer never
  scrolled all the way through.
- **`rewatch_count`** (integer ≥ 0) — how many times the viewer had already dwelled on this same
  post earlier in the same session. `0` on the first dwell, `1` on the second, and so on.

Sending either on a non-`dwell` event, or a value outside its range, still `422`s — same enforcement
as every other per-type metadata field.

Dwell's affinity weight now factors both in: still +1 per 10 seconds (capped at +3), plus +1 if
`completion_rate` is at least 0.9, plus +1 if `rewatch_count` is greater than 0 — max +5 instead of
the previous +3. An event that omits both fields (or predates them) scores exactly as before.

Also: `share`'s required `share_channel` metadata gains a fourth allowed value, **`group`** —
alongside `system`, `copy_link`, and `external` — for the existing "share into a group you've
joined" action (`POST /v1/groups/{group}/posts/{post}`, shipped 2026-07-26), which doesn't fit any
of the original three (none of them mean "stayed inside the app, into an in-app destination").

---

## Shipped: 2026-08-08 — `POST /v1/auth/social/google` now takes `access_token`, not `id_token`

**Breaking change for this one field only** — every other field on this endpoint
(`device_name`) and every other social endpoint (`facebook`'s `access_token`) is unchanged.

Google sign-in verification moved off this app's own hand-rolled JWT/JWKS code and onto
`laravel/socialite`. Socialite's token-based verification is built around an OAuth **access**
token (it calls Google's userinfo endpoint with it as a bearer token), not the ID token this
endpoint accepted before — send the access token your Android client obtains via Google's
Authorization API (`Identity.getAuthorizationClient()`, `email`/`profile`/`openid` scopes), not the
Credential Manager ID token used for any local Firebase exchange you may still be doing on your
own side.

Everything else about this endpoint is unchanged: same response shape, same account-linking
behavior (`email_verified` still gates auto-linking to an existing password account), same 422 on
a missing/invalid token.

`facebook`'s verification also moved onto Socialite (its access-token-based flow already matched
what this endpoint expected — no client-facing change there).

---

## Removed: 2026-08-08 — Apple sign-in

`POST /v1/auth/social/apple` is gone — route, controller action, request validation, and the
custom JWT/JWKS verifier all removed. It was never called by any client (no native Apple SDK on
Android, no client code was ever written against it), so this is a clean removal, not a breaking
change for anyone integrating against it today. `login_method`/`provider` values on every
endpoint that surfaces them (`GET /v1/sessions`, `GET /v1/connected-accounts`, etc.) are now one
of `registration`, `password`, `google`, `facebook` only — `apple` will never appear again,
including on rows created before this change (there were none in production).

`firebase/php-jwt` stays as a composer dependency — nothing to do with social login anymore
(Google's verifier moved to Socialite earlier the same day), but `App\Services\Fcm\FcmClient`
still uses it directly to sign its own JWTs for Firebase Cloud Messaging auth, an unrelated use.

---

## Shipped: 2026-09-02 — `GET /v1/notifications/unread-count`

New endpoint for the bottom-nav notification badge.

- `GET /v1/notifications/unread-count` — `200`, `{"data": {"count": 12}}`.
- Auth: user token (same `auth:sanctum` group as the rest of `/v1/notifications`).
- Throttle: `notifications-read`, shared with the listing.

Counts only the caller's own unread rows (`read_at IS NULL`); a user never sees anyone
else's. `PATCH /v1/notifications/read-all` drives it to `0`, and marking a single one read
decrements it — nothing else to call.

**Behavior to know:**
- The count is **exact and uncapped**. Rendering anything over 99 as `99+` is a client
  decision — the API will happily return `4213`.
- It is deliberately *not* folded into `GET /v1/notifications` as `meta.unread_count`. The
  badge refreshes on every screen that hosts the bottom nav, and piggybacking would pull 20
  notification rows and their resources each time; this is a single indexed `COUNT`.
- Route is declared **before** `notifications/{notification}/read` so `unread-count` can
  never be captured as a notification id. It is a `GET`; the mark-read routes are `PATCH`.

---

## Corrections: 2026-09-02 — response shapes the Android models had wrong

No backend change here — recording it so the shapes stop being guessed. Laravel wraps
**every** `JsonResource` in a `data` key (nothing calls `JsonResource::withoutWrapping`),
and that applies to single resources exactly as it does to collections:

| Endpoint | Actual 201/200 body |
| --- | --- |
| `POST /v1/conversations` | `{"data": { …ConversationResource }}` |
| `POST /v1/conversations/{id}/messages` | `{"data": { …MessageResource }}` |
| `POST /v1/hidden-terms` | `{"data": { …HiddenTermResource }}` |

The Android client had these declared as bare objects, so Moshi threw on a perfectly
successful `201` — a sent message reported "failed to send" while the row was already stored
and the recipient already pushed.

`MessageResource.body` is **nullable**: a message caught by the viewer's hidden-words filter
comes back as `{"body": null, "is_filtered": true}` rather than being dropped from the
thread. Clients must render a placeholder row, not assume a string.

`EmailVerificationController@show` (`GET /v1/auth/email-verification`) is the exception that
proves the rule — it returns a plain `response()->json([...])`, so it has **no** `data`
wrapper. Anything built from a `JsonResource` does.

---

## Shipped: 2026-09-02 — `is_following` on post authors (`UserSummaryResource`)

`UserSummaryResource` gains an **optional** `is_following` boolean. It appears wherever the
server annotated it and is **absent otherwise** — it is never sent as a blanket `false`.

Annotated today on:

- `GET /v1/feed` (Following)
- `GET /v1/feed/for-you`
- `GET /v1/posts/{post}`

**Behavior to know:**
- **Absent means "unknown", not "not following".** Treating a missing value as `false` is the
  bug this fixes: the Following feed is built from `whereIn('user_id', $user->following())`,
  so every author in it is followed by construction — and the client rendered a Follow button
  on every post. If the field is absent, hide the follow control rather than guessing.
- **It is absent on the viewer's own posts**, deliberately. "Am I following myself" is not a
  meaningful question; use that as the signal to hide the button.
- Computed in one bulk query per page (`FollowService::annotatePostAuthorsAreFollowed`), the
  same shape as the existing `is_liked` annotation — no N+1, no per-row cost.
- Other endpoints returning `UserSummaryResource` (search, notification actors, conversation
  participants, follow lists) do **not** annotate it yet, so it stays absent there. Ask before
  building a follow control on those surfaces.

---

## Shipped: 2026-09-02 — folders for private saves

Private saves (`/v1/private-saves`) are now classified into per-user folders. Every account
gets three seeded folders — **General**, **Business**, **Memes** — and the user picks one at
upload time.

### 1. List the folders

`GET /v1/private-save-folders` — not paginated, returns every folder the caller owns in
display order.

```json
{
  "data": [
    { "id": 1, "slug": "general",  "name": "General",  "is_default": true, "position": 0, "saves_count": 12 },
    { "id": 2, "slug": "business", "name": "Business", "is_default": true, "position": 1, "saves_count": 3 },
    { "id": 3, "slug": "memes",    "name": "Memes",    "is_default": true, "position": 2, "saves_count": 41 }
  ]
}
```

- `slug` is the stable key — match on it, not on `name`, which is user-editable text.
- `saves_count` is present **only on this endpoint**; the nested `folder` object elsewhere
  omits the key rather than sending a meaningless `0`.
- `is_default` marks the three seeded folders. They cannot be deleted, which is what
  guarantees a save always has somewhere to live.

### 2. Choose a folder when uploading

`POST /v1/private-saves` takes an optional `folder_id` alongside `image` (still
`multipart/form-data`).

- Omit it and the save is filed under **General**. Existing clients keep working untouched.
- The id must be one of the caller's own folders; anything else is a `422` on `folder_id`.
- Send it as the numeric id, e.g. `3`. This is multipart, so it goes on the wire as the
  string `"3"` — that's fine, `integer` accepts a numeric string. (Unlike `boolean` fields,
  which reject `"true"` — see the group-creation note.)

### 3. Read them back

`GET /v1/private-saves` — unchanged, plus an optional `?folder_id=` filter, and every item
now carries the folder:

```json
{
  "id": 1,
  "folder_id": 3,
  "folder": { "id": 3, "slug": "memes", "name": "Memes", "is_default": true, "position": 2 },
  "url": "…", "width": 1080, "height": 1920,
  "mime_type": "image/jpeg", "size_bytes": 245678,
  "created_at": "2026-07-29T00:00:00.000000Z"
}
```

Omit `folder_id` to list everything (still cursor-paginated, 24 per page). Passing a folder
that isn't yours is a `422`, not an empty page — so a client bug surfaces instead of looking
like an empty folder.

### 4. Move a save

`PATCH /v1/private-saves/{id}` with `{"folder_id": 3}` → `200` with the updated
`PrivateSaveResource`. This is the correction path for a wrong pick at upload time.

**Behavior to know:**
- **Folders are seeded lazily, on first use** — the first call to
  `GET /v1/private-save-folders` or `POST /v1/private-saves` creates them. So call the folder
  list before rendering a picker; don't hard-code the three ids client-side, and don't assume
  the ids are the same for two different users. `slug` is the only thing stable across
  accounts.
- **Existing saves were backfilled into General**, so `folder_id` is non-null on every row
  this app created. It is typed nullable only because folder deletion (not yet exposed)
  would null it.
- A save belonging to someone else `404`s on `PATCH` and `DELETE`, same as before — the API
  doesn't distinguish "not yours" from "doesn't exist".
- These are **private** saves. Nothing here is visible to another user, and none of it is
  related to `ScreenshotCategory` (the global taxonomy on published posts) or to
  saved *collections* (`/v1/collections`, which organize already-published posts).

**Not built yet:** creating, renaming, reordering or deleting custom folders. The schema is
per-user rows precisely so those are additive — ask if the client wants them.
