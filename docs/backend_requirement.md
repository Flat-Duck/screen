# Frontend → Backend Requests

## How to use this doc

Open questions and requests from the Android client, raised while planning the remaining
`docs/frontend-checklist.md` gaps (Search, Hashtags, Explore, Reposts, Direct Messages, plus a
few smaller items). Answer inline under each item (or add a reply block) — this becomes the
shared thread for anything that needs a backend decision, a doc correction, or a new field before
the client can build against it correctly.

---

## 1. `docs/frontend-handoff.md` has an inaccuracy on notification `type` values

Its prose describes the FCM-only `data.type` payload for repost/message notifications as
`"repost"`/`"message"`. But the actual `database`-channel `NotificationResource.type` (the field
the app actually renders in the notifications list) is derived as
`Str::snake(class_basename($notif) minus "Notification")` per `NotificationResource.php` —
which gives `post_reposted` and `new_message`, not `"repost"`/`"message"`. Those two are a
different, FCM-only string used solely inside the push payload's `data` map, not the same value
as `NotificationResource.type`.

**Ask**: please correct `frontend-handoff.md`'s prose to avoid this ambiguity for the next reader.
The client is using the verified-correct `post_reposted`/`new_message` values (confirmed directly
against `NotificationResource.php` and the notification class source), so this isn't blocking —
just flagging the doc drift.

## 2. No `is_muted` field on `UserResource`

`UserResource` already exposes `is_following`/`is_blocked`/`is_blocked_by` (each omitted when
viewing your own profile), but nothing for mute state. This means any screen showing another
user's mute state — today's user-lookup screen, and an upcoming "Muted Users" management screen —
can only ever reflect taps made in the current client session, or a separate
`GET /v1/muted-users` list cross-reference. There's no persisted single source of truth the way
follow/block already have.

**Ask**: would it be reasonable to add `is_muted` to `UserResource` (same `when()`-omitted-on-own-
profile pattern as the other three)? This would remove an entire class of stale-mute-state bugs
client-side.

## 3. No `is_following` on `UserSummaryResource`

`UserSummaryResource` (`{id, username, name, avatar_url}`) is used to embed "who" inside posts,
comments, followers/following lists, and (soon) search results and conversation participants.
None of those contexts can show correct follow-state for the embedded user without a second
`GET /v1/users/{id}` round-trip per row. The client already accepts this gap for feed post
authors today (rendering everyone as "Follow" until tapped, session-only), but it's about to
multiply across Search's People tab and Direct Message participants too.

**Ask**: would it be reasonable to add `is_following` (and maybe `is_blocked`, for parity) to
`UserSummaryResource`, at least in contexts where the viewer is authenticated? If this can't be
done everywhere, even just on the Search-users endpoint's results would help.

## 4. `GET /v1/explore?page=` — pagination-completion signal is unclear

The checklist documents this as the one page-number-paginated endpoint in the whole API (every
other list is cursor-based, `meta.next_cursor`). It's not clear from the checklist alone what the
response's `meta` actually contains for this endpoint — does it reuse the same `FeedMeta` shape
(`path`, `per_page`, `next_cursor`, `prev_cursor` — none of which are meaningful for page-based
pagination), a different Laravel-standard page-based shape (`{current_page, last_page, per_page,
total}`), or nothing at all (client must infer "last page" from getting back an empty/short page)?

**Ask**: could you paste the literal JSON body of a real `GET /v1/explore?page=1` response
(especially its `meta`/`links` block)? The client needs this to implement a correct
"stop paginating" condition rather than guessing "empty page = done," which would work but may
mask an off-by-one or a different intended signal.

## 5. Does `POST /v1/posts/{id}/repost` expect an `Idempotency-Key` header?

Every existing "relationship" mutation (follow/block/mute) requires an `Idempotency-Key` header;
every existing "simple toggle" (like/save) doesn't. The checklist documents repost as idempotent
but doesn't say which convention it follows.

**Ask**: please confirm whether the client should send `Idempotency-Key` on
`POST /v1/posts/{id}/repost`, or whether it's a plain idempotent-by-nature endpoint like like/save.

## 6. Direct Messages — three small clarifications

- **Poll rate limiting**: is there a rate limit specifically on `GET /v1/conversations/{id}/messages?after=`
  (distinct from whatever limit applies to the send endpoint) that should upper-bound how
  aggressively the client polls an open thread? The client plans to start at a 4-5s interval,
  only while the thread screen is actually in the foreground — happy to adjust to whatever number
  you'd recommend.
- **`ConversationResource.unread`**: is this a boolean or a count? The documented shape
  (`{id, other_participant, last_message_at, unread}`) doesn't disambiguate, and it changes
  whether the client renders a dot or a numeric badge on the conversations list.
- **`after=` boundary**: is `GET .../messages?after=<message_id>` inclusive or exclusive of that
  message's own id? Needed to avoid an off-by-one duplicate message bubble right at the poll
  boundary.

## 7. Hashtag extraction character set

Since `PostResource` has no structured tags field, the client plans to regex-extract `#word`
substrings directly out of post captions (`#\w+`) to render tappable hashtag chips inline on post
cards, rather than waiting on/requesting a structured field.

**Ask**: could you confirm (or point to) the exact character set/rule the backend uses when
indexing a caption's hashtags into the `hashtags` table on post create/edit? If the client's
regex is looser or stricter than the server's own extraction, some chips could render as tappable
but 404 on `GET /v1/hashtags/{name}`, or some server-indexed tags might not get a chip at all.

---

Nothing above is blocking except #4 (Explore's pagination shape) — everything else has a
reasonable client-side fallback in the meantime and can be answered whenever convenient.
