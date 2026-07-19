# API Integration Checklist

## How to use this doc

Every `/api/v1/*` endpoint that currently exists and works, in one place. Go through each
row: mark **Done** once your side is wired up and matches the behavior described, or leave
it unchecked and use **Notes** to flag anything you need changed, clarified, or that doesn't
match what you're seeing. This file lives in the repo (`docs/frontend-checklist.md`) — edit
it directly and it becomes the shared source of truth for integration status; ping the
backend whenever you fill in a note that needs a response.

This is a snapshot, not a changelog — for *why* something was built a certain way, prose
explanations, and what's still to come, see `docs/frontend-handoff.md`. For request/response
examples with real sample data, see the Postman collection
(`postman/Screenshot-Social-API.postman_collection.json`).

All endpoints below require `Authorization: Bearer <token>` unless noted otherwise. Two
distinct token types exist — **Device token** (from `/devices/enroll`) and **User token**
(from register/login) — each endpoint below says which one it expects. List endpoints are
cursor-paginated (`GET ...?cursor=`, response has `links`/`meta`) unless marked otherwise.

---

## Common response shapes

Referenced by name below instead of repeating the full field list on every row.

**`UserSummaryResource`** — lightweight, used when embedding "who" inside another resource:
`{id, username, name, avatar_url}`

**`UserResource`** — full profile:
`{id, username, name, bio, avatar_url, country_code, birth_date, posts_count, followers_count, following_count, is_following, is_blocked, is_blocked_by, created_at}`
— `birth_date` only appears on your own profile. `is_following`/`is_blocked`/`is_blocked_by`
are omitted entirely when viewing your own profile (meaningless there).

**`PostResource`**:
`{id, caption, status, user: UserSummaryResource, media: PostMediaResource[], likes_count, comments_count, is_liked, is_saved, created_at, edited_at}`
— `status` is `processing`/`ready`/`failed` (thumbnail generation only, post is visible
either way). `edited_at` is `null` until the post has been edited.

**`PostMediaResource`**: `{id, position, url, original_url, width, height, status}`
— `url` is the thumbnail, falling back to `original_url` automatically while
`status=processing`; always safe to render.

**`CommentResource`**:
`{id, parent_id, body, user: UserSummaryResource, replies_count, likes_count, is_liked, created_at}`
— `parent_id` is `null` for a top-level comment.

**`HashtagResource`**: `{name, posts_count, is_followed}` — no numeric `id` exposed; tags
are referenced by name everywhere.

**`RepostResource`**: `{id, comment, post: PostResource, created_at}`

**`ConversationResource`**: `{id, other_participant: UserSummaryResource, last_message_at, unread}`

**`MessageResource`**: `{id, conversation_id, body, sender: UserSummaryResource, created_at}`

**`NotificationResource`**: `{id, type, data, read_at, created_at}` — `type` is a short
string (`new_follower`, `post_liked`, `post_commented`, `comment_replied`,
`comment_liked`, `mentioned`, `post_reposted`, `new_message`); `data` shape varies by type
(see `docs/frontend-handoff.md` for each type's fields).

**Error shapes** — same everywhere: `401` no/invalid token; `403` policy denial or blocked
interaction; `404` not found (also used to hide blocked-either-way content); `422` validation
errors as `{"message": "...", "errors": {"field": ["..."]}}`; `429` rate-limited.

---

## Device & Auth

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `POST /v1/devices/enroll` | none | Register/re-enroll a device, get a Device token. Re-enrolling an existing `device_uuid` requires that device's *current* token as Bearer auth (proof of possession). | `device_uuid` (uuid, required), `os_name` (required), `manufacturer`/`brand`/`model`/`os_version`/`sdk_int`/`app_version_name`/`app_version_code` (optional) | `{device_uuid, token}` — 201 if new, 200 if re-enrolled | ☐ | |
| `PUT /v1/devices/push-token` | Device | Set this device's FCM token | `fcm_token` (required, string) | 204 | ☐ | |
| `DELETE /v1/devices/push-token` | Device | Clear the FCM token | — | 204 | ☐ | |
| `POST /v1/auth/register` | Device | Create a user account, get a User token | `name`, `username` (3-30, alpha_dash, unique), `email` (unique), `password` (+`password_confirmation`), `device_name` (optional) | 201: `{user: UserResource, token, session_id, profile_completion}` | ☐ | |
| `POST /v1/auth/login` | Device | Email/username + password login | `login` (email or username), `password`, `device_name` (optional) | Same shape as register, or `{requires_two_factor: true, two_factor_token}` if 2FA is enabled | ☐ | |
| `POST /v1/auth/social/google` | Device | Sign in with a Google ID token | `id_token`, `device_name` (optional) | Same as login; 201 + `is_new_account: true` if this created a new account | ☐ | |
| `POST /v1/auth/social/facebook` | Device | Sign in with a Facebook access token | `access_token`, `device_name` (optional) | Same as Google | ☐ | |
| `POST /v1/auth/social/apple` | Device | Sign in with Apple | `identity_token`, `given_name`/`family_name` (**only sent by Apple on first authorization** — capture and forward then, omit after), `device_name` (optional) | Same as Google | ☐ | |
| `POST /v1/auth/two-factor-challenge` | Device | Step 2 of login when step 1 returned `requires_two_factor` | `two_factor_token`, exactly one of `code` (TOTP) or `recovery_code`, `device_name` (optional) | Same shape as login | ☐ | |
| `POST /v1/auth/logout` | User | Revoke the current session/token | — | 204 | ☐ | |
| `POST /v1/auth/password` | User | Set a password (social-only accounts setting one for the first time, or changing an existing one) | `current_password` (required only if a password already exists), `password` + `password_confirmation` | `{profile_completion}` | ☐ | |

---

## Telemetry (crash & event reporting)

A separate domain from the social features above — device-scoped, not user-scoped. Same
Device token as enrollment/push-token, not the User token.

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `POST /v1/telemetry/events` | Device | Batch-ingest crash/event reports. Resending after an ambiguous network failure is safe — insertion is keyed on `event_id`, resent duplicates are silently accepted again, not double-counted | See below | `{accepted_event_ids: [uuid, ...]}` — a partial batch can be accepted; compare against the `event_id`s you sent to see what was rejected | ☐ | |

**Request body**: `{app: {version_name, version_code, build_type}, os_version, events: [...]}` —
`app`/`events` required, `os_version` optional. Each item in `events[]`:
`{event_id (uuid, required, your idempotency key), session_id (uuid, optional), kind ("event"|"error"|"fatal_crash", required), name (≤100 chars, required), occurred_at (date, required), extras (object, optional), breadcrumbs ([{ts, type, name, extras?}], optional, ≤50 items), error (optional, required only for kind="error"/"fatal_crash": {tag, exception_class, message?, stack_trace, thread_name, is_fatal})}`.
Max 50 events per batch, max 512KB total request size, combined `extras`+`breadcrumbs` JSON
capped at 16KB per event (`422` if exceeded). `session_id`, if sent, must be a session UUID
the server already knows about — invalid ones are dropped from that event, not the whole
batch.

---

## Profile & Account

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `PATCH /v1/profile` | User | Update your own profile | `name`, `username` (optional, unique), `bio` (nullable, ≤500), `avatar` (nullable, image file ≤5MB, min 100x100), `birth_date` (nullable, date, before today), `country_code` (nullable, 2-letter) — multipart if sending `avatar` | `UserResource` + `{profile_completion}` | ☐ | |
| `POST /v1/account/email` | User | Request an email change — **does not change it immediately**, only sets `pending_email`; the live email changes only when the link mailed to the new address is clicked | `email` (unique, not your current one) + step-up field (see below) | `{pending_email}` | ☐ | |
| `POST /v1/account/confirmation-code` | User | Send yourself a step-up email code — only works for accounts with neither a password nor 2FA | — | 204 | ☐ | |
| `DELETE /v1/account` | User | Soft-delete your account | Step-up field | 204 | ☐ | |

**Step-up auth**: destructive/identity-changing actions (`account/email`, `account`, 2FA
enable/disable/regenerate-codes, unlink connected account) require **one** of
`current_password`, `two_factor_code`, or `confirmation_code` — whichever the account
actually has configured (password → `current_password`; 2FA → `two_factor_code`; neither →
must call `POST /v1/account/confirmation-code` first, then send `confirmation_code`). A
`422` on these fields means the wrong one was sent for this account.

---

## Sessions & Two-Factor

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/sessions` | User | List your active/past sessions ("log out other devices" screen) | — | `{session_id, login_method, device: {...}, last_seen_at, started_at, ended_at, end_reason, two_factor_verified_at, revoked_at, status, is_revoked, is_current}[]` (not cursor-paginated) | ☐ | |
| `DELETE /v1/sessions/{sessionId}` | User | Revoke one session (`sessionId` is a UUID, not the numeric id) | — | 204 | ☐ | |
| `POST /v1/sessions/revoke-others` | User | Revoke every session except the current one | `current_password` (conditionally required, see Step-up above) | 204 | ☐ | |
| `GET /v1/two-factor` | User | Check if 2FA is enabled | — | `{enabled: bool}` | ☐ | |
| `POST /v1/two-factor` | User | Enable 2FA — returns everything in one call (not Fortify's usual multi-step web flow) | Step-up field | `{qr_code_svg, qr_code_url, recovery_codes}` | ☐ | |
| `POST /v1/two-factor/confirm` | User | Confirm 2FA setup with a TOTP code (no step-up needed — the code itself is proof) | `code` (required) | `{enabled: true}` | ☐ | |
| `DELETE /v1/two-factor` | User | Disable 2FA | Step-up field | 204 | ☐ | |
| `POST /v1/two-factor/recovery-codes` | User | Regenerate recovery codes (invalidates old ones) | Step-up field | `{recovery_codes}` | ☐ | |

---

## Settings & Connected Accounts

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/settings` | User | Get notification preferences | — | `{data: {notifications: {likes, comments, follows, mentions, reposts, messages}}}` (all bool) | ☐ | |
| `PATCH /v1/settings` | User | Update preferences — partial updates only touch the keys you send | `{notifications: {<any subset of the above keys>: bool}}` | Same shape as GET | ☐ | |
| `GET /v1/connected-accounts` | User | List linked social sign-in providers | — | `{provider, avatar_url, connected_at}[]` (not cursor-paginated) | ☐ | |
| `DELETE /v1/connected-accounts/{provider}` | User | Unlink a provider | Step-up field | 204 | ☐ | |

---

## Profile Viewing, Follow, Block, Mute

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/users/{id}` | User | View a profile | — | `UserResource` — 404 if blocked-either-way | ☐ | |
| `GET /v1/users/{id}/posts` | User | A user's posts | — | `PostResource[]` — 404 if blocked-either-way | ☐ | |
| `GET /v1/users/{id}/top-tags` | User | That user's 5 most-used hashtags | — | `{data: {name, posts_count}[]}` (capped at 5, not cursor-paginated) | ☐ | |
| `GET /v1/users/{id}/reposts` | User | That user's reposts (never blended into `.../posts`) | — | `RepostResource[]` — 404 if blocked-either-way | ☐ | |
| `POST /v1/users/{id}/follow` | User | Follow (idempotent) | — | 204 — 403 if blocked-either-way | ☐ | |
| `DELETE /v1/users/{id}/follow` | User | Unfollow (idempotent) | — | 204 | ☐ | |
| `GET /v1/users/{id}/followers` | User | Followers list | — | `UserSummaryResource[]` | ☐ | |
| `GET /v1/users/{id}/following` | User | Following list | — | `UserSummaryResource[]` | ☐ | |
| `POST /v1/users/{id}/block` | User | Block (idempotent). Auto-unfollows both directions. `422` if targeting yourself | — | 204 | ☐ | |
| `DELETE /v1/users/{id}/block` | User | Unblock (idempotent) | — | 204 | ☐ | |
| `GET /v1/blocked-users` | User | Users you've blocked | — | `UserSummaryResource[]` | ☐ | |
| `POST /v1/users/{id}/mute` | User | Mute (idempotent, `422` on self). One-directional — doesn't restrict the muted user at all, only filters *your* feed/notifications | — | 204 | ☐ | |
| `DELETE /v1/users/{id}/mute` | User | Unmute (idempotent) | — | 204 | ☐ | |
| `GET /v1/muted-users` | User | Users you've muted | — | `UserSummaryResource[]` | ☐ | |

**Note on Block's 404s**: viewing a blocked-either-way user's profile/posts/comments returns
`404`, not `403` — this is deliberate, it doesn't reveal which of you initiated the block.

---

## Search, Hashtags & Explore

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/search/users?q=` | User | Search by username/name substring. Excludes inactive accounts, yourself, blocked-either-way | `q` (required, 1-100 chars) | `UserSummaryResource[]` | ☐ | |
| `GET /v1/search/posts?q=` | User | Search post captions | `q` (required) | `PostResource[]` | ☐ | |
| `GET /v1/search/hashtags?q=` | User | Search hashtag names (case-insensitive) | `q` (required) | `HashtagResource[]` | ☐ | |
| `GET /v1/hashtags/{name}` | User | Hashtag detail. Case-insensitive, `#` tolerated. 404 if never used | — | `HashtagResource` | ☐ | |
| `GET /v1/hashtags/{name}/posts` | User | Posts tagged with this hashtag | — | `PostResource[]` | ☐ | |
| `POST /v1/hashtags/{name}/follow` | User | Follow a tag (idempotent). Bookmark-only — no notifications, not blended into feed | — | 204 | ☐ | |
| `DELETE /v1/hashtags/{name}/follow` | User | Unfollow a tag (idempotent) | — | 204 | ☐ | |
| `GET /v1/hashtags/followed` | User | Your followed hashtags | — | `HashtagResource[]` | ☐ | |
| `GET /v1/explore?page=` | User | Standalone trending feed. **Page-number paginated, not cursor** — the only endpoint like this in the API | `page` (optional, default 1) | `PostResource[]` | ☐ | |

---

## Feed & Posts

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/feed` | User | Following feed, reverse-chronological. First page (no `cursor` param) blends in a couple of trending out-of-network posts | — | `PostResource[]` | ☐ | |
| `POST /v1/posts` | User | Create a post (multipart) | `caption` (nullable, ≤2200), `images[]` (1-10 files, jpeg/png/webp, ≤10MB each, min 200x200) | 201: `PostResource` (`status: "processing"` — show it immediately, `media[].url` already has a fallback) | ☐ | |
| `GET /v1/posts/{id}` | User | Post detail | — | `PostResource` — 404 if blocked-either-way | ☐ | |
| `PATCH /v1/posts/{id}` | User | Edit caption. **Omit `caption` key = unchanged; send `caption: null` = clear it.** Media can't be edited | `caption` (optional, nullable, ≤2200) | `PostResource` (sets `edited_at`) | ☐ | |
| `DELETE /v1/posts/{id}` | User | Delete your own post | — | 204 — 403 if not yours | ☐ | |

---

## Likes & Saves

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `POST /v1/posts/{id}/like` | User | Like a post (idempotent) | — | `{likes_count}` — 403 if blocked-either-way with post owner | ☐ | |
| `DELETE /v1/posts/{id}/like` | User | Unlike (idempotent) | — | `{likes_count}` | ☐ | |
| `POST /v1/comments/{id}/like` | User | Like a comment (idempotent) | — | `{likes_count}` — 403 if blocked-either-way with the comment's author | ☐ | |
| `DELETE /v1/comments/{id}/like` | User | Unlike a comment (idempotent) | — | `{likes_count}` | ☐ | |
| `POST /v1/posts/{id}/save` | User | Bookmark a post privately (idempotent) | — | 204 | ☐ | |
| `DELETE /v1/posts/{id}/save` | User | Unsave (idempotent) | — | 204 | ☐ | |
| `GET /v1/saved-posts` | User | Your saved posts (private — no way to view anyone else's) | — | `PostResource[]` | ☐ | |

---

## Reposts

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `POST /v1/posts/{id}/repost` | User | Repost, optionally with a quote comment (idempotent — re-reposting doesn't update an existing comment). `422` on reposting your own post | `comment` (optional, nullable, ≤2200) | 204 — 403 if blocked-either-way | ☐ | |
| `DELETE /v1/posts/{id}/repost` | User | Un-repost (idempotent) | — | 204 | ☐ | |

(Listing reposts is `GET /v1/users/{id}/reposts`, in the Profile section above — v1 never
blends reposts into anyone's home feed.)

---

## Comments

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/posts/{id}/comments` | User | Top-level comments only (not replies) | — | `CommentResource[]` — 404 if blocked-either-way | ☐ | |
| `POST /v1/posts/{id}/comments` | User | Add a comment, or a reply via `parent_id` (must be an existing **top-level** comment on the same post — replying to a reply is rejected, one level of nesting only) | `body` (required, ≤2200), `parent_id` (optional) | 201: `CommentResource` — 403 if blocked-either-way with post owner | ☐ | |
| `GET /v1/comments/{id}/replies` | User | Replies to a top-level comment | — | `CommentResource[]` | ☐ | |
| `DELETE /v1/comments/{id}` | User | Delete a comment — allowed if you're the comment's author **or** the post's owner. Deleting a top-level comment cascades to its replies | — | 204 — 403 if neither | ☐ | |

---

## Notifications & Reports

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `GET /v1/notifications` | User | Your notifications, 20/page | — | `NotificationResource[]` | ☐ | |
| `PATCH /v1/notifications/{id}/read` | User | Mark one read | — | 204 | ☐ | |
| `PATCH /v1/notifications/read-all` | User | Mark all read | — | 204 | ☐ | |
| `POST /v1/reports` | User | Report a post/comment/user | `reportable_type` (`post`\|`comment`\|`user`), `reportable_id`, `reason` (`spam`\|`harassment`\|`nudity`\|`other`), `details` (optional, ≤2000) | 201: `{id, reportable_type, reason, status, created_at}` — write-only, no way to list your own past reports | ☐ | |

---

## Direct Messages

| Endpoint | Token | Description | Request | Response | Done | Notes |
|---|---|---|---|---|:---:|---|
| `POST /v1/conversations` | User | Start (or find existing) 1:1 conversation. Idempotent — messaging someone you already have a thread with returns that same thread. `422` on self or blocked-either-way | `user_id` (required) | 201: `ConversationResource` | ☐ | |
| `GET /v1/conversations` | User | Your conversations, most recent activity first | — | `ConversationResource[]` | ☐ | |
| `PATCH /v1/conversations/{id}/read` | User | Mark **your** read marker on this conversation — 403 if you're not a participant | — | 204 | ☐ | |
| `GET /v1/conversations/{id}/messages` | User | Two modes: no `after` param = cursor-paginated history, newest first. `?after=<message_id>` = flat array (no pagination meta) of everything newer, oldest first, capped at 100 — for polling an open thread. 403 if not a participant | `after` (optional) | `MessageResource[]` | ☐ | |
| `POST /v1/conversations/{id}/messages` | User | Send a message. 403 if not a participant, or if the other participant is now blocked-either-way (history stays visible either way — only sending is blocked) | `body` (required, ≤2200) | 201: `MessageResource` | ☐ | |

**No realtime socket** — this API has no WebSocket/Reverb infra. Poll `?after=` while a
thread is open; rely on push notifications (`type: "new_message"`) for backgrounded delivery.
**1:1 only** — no group chat, no message requests/approval gate for strangers in v1.

---

## Summary

| Area | Endpoints | Done |
|---|---|---|
| Device & Auth | 11 | ☐ / 11 |
| Telemetry | 1 | ☐ / 1 |
| Profile & Account | 4 | ☐ / 4 |
| Sessions & Two-Factor | 8 | ☐ / 8 |
| Settings & Connected Accounts | 4 | ☐ / 4 |
| Profile Viewing, Follow, Block, Mute | 14 | ☐ / 14 |
| Search, Hashtags & Explore | 9 | ☐ / 9 |
| Feed & Posts | 5 | ☐ / 5 |
| Likes & Saves | 7 | ☐ / 7 |
| Reposts | 2 | ☐ / 2 |
| Comments | 4 | ☐ / 4 |
| Notifications & Reports | 4 | ☐ / 4 |
| Direct Messages | 5 | ☐ / 5 |
| **Total** | **78** | |
