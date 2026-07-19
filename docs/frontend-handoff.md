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

- `GET /v1/search/users?q=` — cursor-paginated `UserSummaryResource[]`, matches
  username/name substring. Excludes: inactive accounts, yourself, and anyone blocked
  either-way (silently — they just don't appear in results, no error).
- `GET /v1/search/posts?q=` — cursor-paginated `PostResource[]`, matches caption substring.
- `GET /v1/search/hashtags?q=` — cursor-paginated response with `name`/`posts_count`/
  `is_followed` per hashtag (not a full `PostResource`-style object — hashtags have no `id`
  exposed, only `name`, since that's how they're referenced everywhere else in the API too,
  including the new browse endpoints below).
- All three: plain substring matching, no relevance ranking (a prefix match doesn't rank
  above a mid-string match) — **don't build a UI that assumes results are sorted by
  relevance**, they're sorted alphabetically/by recency instead. Smarter ranking is a
  possible future improvement, not present now.
- Separate, tighter rate limit than most reads: 20/min per user (vs. 60/min for `reads`).
- `q` is required, 1–100 chars — a `422` on `q` if omitted/empty, same validation-error
  shape as everywhere else in the API.

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

Nothing is currently in progress on the backend — the full feature roadmap from the
2026-07-19 audit (safety/moderation, engagement depth, discovery, sharing & messaging) has
shipped end to end. Explicitly deferred, no timeline, no work started: Groups, Stories,
encrypted Vault sync, per-viewer feed personalization/ML ranking. Same status as
`docs/mobile-app-plan.md` already describes — nothing new here. If/when a new phase of
backend work starts, it'll show up as a new dated section above this one, same as every
other entry in this doc.
