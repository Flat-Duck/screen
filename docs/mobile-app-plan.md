# Mobile App Plan — Wiring the Snap Vault (Stitch) Designs to the v1 API

## Context

The Android app uses one `/api/v1` backend for social features and device-scoped crash telemetry.
Every installation must enrol through `POST /api/v1/devices/enroll` before registration or login.

A Stitch design export ("Snap Vault", `stitch_snap_vault_app_design_system.zip`) exists for this, but it designs a considerably larger product than the v1 API supports: alongside screens that map cleanly (Sign Up, Profile Setup, Home Feed, Screenshot Detail, User Profile), it also includes **Groups** (shared albums), **Discover** (a public content grid), and a PIN/biometric-locked **Vault** — none of which the API has endpoints for, plus a few UI details (per-comment likes, threaded replies, tags, a feed-card "source badge") that don't match any field the API returns.

Decisions already made (confirmed with the app owner):
- **This plan wires up only what the v1 API already supports.** Groups, Discover, and syncing Vault to a server are out of scope here — see [Phase 2 backlog](#phase-2--v2-backlog) for what they'd need later.
- **Vault is fully local** — PIN/biometric gating and its private-collection storage live entirely on-device (Android Keystore / BiometricPrompt / local encrypted storage). No backend calls, ever, for Vault content.
- **Screenshot Detail's comment thread is simplified to match the API** — flat comments, no per-comment like button, no "Reply" affordance. A real "like the post" action (which the API *does* support) should be added near the share/download actions instead, since the mockup doesn't show one.

## Screen inventory & status

| Stitch screen | Status | Notes |
|---|---|---|
| `splash_screen_light` | 🏠 Local | Pure UI, no data. |
| `welcome_carousel_light` | 🏠 Local | Static onboarding copy. |
| `sign_up_light` | 🔧 Needs a field added | Maps to `POST /v1/auth/register`, but the form only collects email + password — no `name`/`username` field exists anywhere in the export. See [Design gaps](#design-gaps-to-resolve). |
| *(Login — not in the export)* | ⚠️ Needs designing | `sign_up_light` links to "Log In" but no login screen was exported. Build one matching the sign-up style, wired to `POST /v1/auth/login`. |
| `profile_setup_light` | ✅ Maps (mostly) | Username/bio/avatar map directly; "Curation Interests" tags have no backing field — drop for v1 (see gaps). |
| `permissions_light` | 🏠 Local | Android photo-library permission priming; no API call. |
| `home_feed_light` | 🔧 Needs simplification | Maps to `GET /v1/feed`, but card fields (source badge, tags) don't exist on `Post` — see mapping below. |
| `discover_grid_light` | 🚧 Phase 2 | No public/discover endpoint exists (v1 feed is following-only, by design). |
| `screenshot_detail_light` | 🔧 Needs simplification | Maps to post detail + comments; drop per-comment likes/reply, add a post-level like button. |
| `share_screen_light` | 🔧 Needs simplification | Only the "Public Feed" destination is wired to the API; "Personal Vault" saves locally; "Private Groups" is hidden. |
| `user_profile_light` | 🔧 Needs simplification | Follow + stats map directly; "Collections" and "Liked" tabs have no backing endpoint yet. |
| `groups_list_light` / `group_detail_light` | 🚧 Phase 2 | No Groups API at all. |
| `vault_grid_light` / `vault_locked_light` / `vault_pin_setup_light` | 🏠 Local | Fully on-device per the decision above. |

## Per-screen API wiring

### Sign Up → `POST /v1/auth/register`
Collect email + password (+ confirmation) on this screen only. **Don't call the API here** — hold the values in the sign-up flow's shared state and fire the actual `register` call from Profile Setup's "Complete Setup" button instead, once `name`/`username` are also known (see gap below). This avoids a half-registered account if the user abandons Profile Setup.

### Login (to be designed) → `POST /v1/auth/login`
Request: `{ "login": <email or username>, "password": ..., "device_name": <Build.MODEL or user label> }`. On success, store `token` (see [auth storage](#auth--token-storage)) and the returned `user` object as the current-session user.

### Profile Setup → `POST /v1/auth/register` then `PATCH /v1/profile`
1. On "Complete Setup": call `register` with `{ name, username, email, password, password_confirmation, device_name }` — `email`/`password` carried over from Sign Up, `username` from this screen's `@yourname` field, `name` from the new field described in the gap below. Store the returned token.
2. If an avatar or bio was set, immediately call `PATCH /v1/profile` (multipart: `bio` text field, `avatar` file field) with the new token.
3. "Skip for Now" still requires step 1 (an account must exist) but skips step 2 entirely.
4. Drop the "Curation Interests" chip picker for v1 — there's no field for it. Hide the section rather than shipping a picker that silently does nothing.

### Home Feed → `GET /v1/feed`
Cursor-paginated (Paging 3 `PagingSource`, cursor from `meta.next_cursor`). Each `PostResource` gives you: `caption`, `media[]` (each with `url` — thumbnail-or-original fallback already resolved server-side — `width`/`height`, `status`), `user` (`UserSummaryResource`: `id`/`username`/`name`/`avatar_url`), `likes_count`, `comments_count`, `is_liked`.

Card-level changes needed vs. the mockup: drop the "source badge" (TWITTER/FIGMA/etc.) and hashtag chips — `Post` has neither a source field nor tags. Use `caption` where the mockup shows a title/description. Show `likes_count`/`comments_count` (the mockup doesn't display these on feed cards at all — add them, e.g. under the image, matching `screenshot_detail`'s style). The FAB (`add`) opens Share Screen.

### Screenshot Detail → `GET /v1/posts/{id}` + comments endpoints
- Hero image / gallery: `media[]`, `caption`, `user`.
- Add a **post-level like button** (not in the mockup — needed because the API only supports liking posts, not comments): `POST /v1/posts/{id}/like` / `DELETE .../like`, toggling on `is_liked`, updating the count from the response's `likes_count`.
- Comment thread: `GET /v1/posts/{id}/comments` (cursor-paginated, oldest-first), `POST /v1/posts/{id}/comments` (body: `{"body": "..."}`) to add, `DELETE /v1/comments/{id}` to remove (only show the delete affordance when `comment.user.id == currentUserId` **or** `post.user.id == currentUserId` — the API allows either, matching a post owner's ability to moderate their own post's comments).
- **Drop**: per-comment like button (`thumb_up` + count) and "Reply" — no backing endpoints.
- "Download Original" — just fetch `media[].original_url` directly, no API endpoint needed.
- Overflow menu (`more_vert`): show "Delete Post" only when the current user owns the post, wired to `DELETE /v1/posts/{id}`.
- "Share" (top bar icon): native Android share sheet with the image/deep link — not an API call.

### Share Screen → `POST /v1/posts` (Public Feed destination only)
- Multipart request: `caption` (text) + `images[]` (1–10 files, each ≤10MB, jpeg/png/webp). Reuse the screenshot files the app's existing local screenshot-detector already indexes as the source for the file picker.
- Destination selector: keep all three cards visually (matches the design system), but:
  - **"Public Feed"** → the real `POST /v1/posts` call described above.
  - **"Personal Vault"** → save to local encrypted storage only; no network call at all.
  - **"Private Groups"** → disable this card (or hide it) for v1 with a "Coming soon" state — there's no Groups API yet.
- Tags chips (#Architecture etc.) — same as Home Feed, no backing field; drop for v1 or keep purely as local-only metadata if useful for the (local) Vault destination.
- On success (`201`), the response's `data.status` will be `"processing"` — show the post immediately using `media[].url` (already falls back to the original if the thumbnail isn't ready) rather than blocking on a loading state.

### User Profile → `GET /v1/users/{id}` + `GET /v1/users/{id}/posts`
- Stats: `posts_count` → "Snapshots", `following_count` → "Following", `followers_count` → the mockup's third stat is labeled **"Curators"** — confirm with design whether that's intentional terminology or should just read "Followers"; either way it's `followers_count` under the hood.
- Follow button: `POST /v1/users/{id}/follow` / `DELETE .../follow`, reflecting `is_following` from the response (only present when viewing someone else's profile).
- "Latest Snapshots" tab → `GET /v1/users/{id}/posts` (cursor-paginated, same `PostResource` shape as the feed).
- "Collections" and "Liked" tabs → no backing endpoint. Hide both for v1 (see [Phase 2](#phase-2--v2-backlog) — "Liked" in particular is a small addition later).
- `mail` icon (DM) and the `verified` badge overlay → no messaging API and no verified-account field exist; drop both for v1.

### Followers / Following lists
If/where the design needs them: `GET /v1/users/{id}/followers` and `GET /v1/users/{id}/following` (cursor-paginated `UserSummaryResource[]`).

### Permissions, Splash, Welcome Carousel, Vault (all three)
No wiring — implement exactly as designed, purely client-side. Vault's PIN/biometric flow should use `BiometricPrompt` + Android Keystore-backed local storage; treat its "collections" and "private screenshots" as local data (e.g. a local DB), unconnected to anything in this plan.

## Design gaps to resolve

1. **No field captures the user's display `name`** anywhere in the export (only the `@username` handle) — but `POST /v1/auth/register` requires it. Recommend adding a short "Display Name" text field to Profile Setup (default-filled from the username, editable), rather than silently reusing the username as the name.
2. **No Login screen was exported** — only referenced via a link from Sign Up. Needs designing (can closely mirror Sign Up's layout).
3. **PIN length is inconsistent**: `vault_locked_light` shows a 4-digit keypad/dots; `vault_pin_setup_light`'s copy says "6-digit" (and that screen's export is itself truncated — verify the real intended length before building the local security flow).
4. **Bottom-nav "active tab" is wrong on several screens** (`screenshot_detail`, `user_profile`, `profile_setup` all show the Vault tab as active) — looks like leftover template state from copy-pasting the nav markup, not intentional. Don't use "active tab per mockup" as a source of truth; derive it from the screen's actual section.
5. **The 4th nav tab's label varies** (Network / Curators / Shared / plain icon) across screens depending on which section it points to — since Groups is Phase 2, this tab can just stay icon-only (or hidden) for the v1 release.
6. **Two visual treatments for the FAB** (separate floating button vs. embedded-in-nav-bar) — pick one Android pattern and use it consistently; recommend a single floating FAB, since it's the more standard Android affordance.

## Cross-cutting Android architecture

### Auth and token storage
Store two credentials under distinct encrypted keys. The Device token calls enrollment rotation,
FCM, authentication, 2FA completion, and telemetry endpoints. The User token calls social/account
APIs. Authentication responses also return `session_id`; record that UUID with each crash so delayed
uploads retain the correct user attribution after logout or account switching.

### Networking
Use separate OkHttp clients/interceptors for Device and User credentials. Both target `/api/v1`, so
route path alone cannot choose the credential; the Retrofit service interface must use the correct
client explicitly.

### Pagination
Every list endpoint (`feed`, `users/{id}/posts`, `followers`, `following`, `posts/{id}/comments`) is cursor-paginated (`meta.next_cursor`, `links.next`). Use Jetpack Paging 3 with a `PagingSource` keyed on the cursor param, not page numbers.

### Images
Use Coil (Kotlin-first, simpler than Glide for this). Load `media[].url` for feed/grid thumbnails (already resolved server-side to thumbnail-or-original) and `original_url` for the detail view / download. For uploads, multipart the same local screenshot files the app already indexes.

### Error handling
- `401` → token invalid/expired, force re-login.
- `403` → policy denial (e.g. deleting someone else's post/comment) — the UI should already hide these actions for non-owners, but handle the response gracefully as a fallback.
- `422` → validation errors; map `errors.<field>` array messages to the corresponding form field.
- `429` → rate-limited (see the Postman collection's per-endpoint limits, e.g. register 5/min, post upload 10/min) — surface a "try again in a moment" message rather than a generic error.

## Phase 2 / v2 backlog

Not part of this plan — listed so nothing gets lost:
- **Groups**: shared albums, membership, invites, group-scoped uploads, activity feed.
- **Discover**: public/global content grid with search + category filters, separate from the personal following-feed.
- **Comment likes + threaded replies**: would need a `comment_likes` table/endpoints and a `parent_id` on `comments`.
- **"Liked" profile tab**: a small addition — `GET /v1/users/{id}/liked-posts`.
- **Tags/hashtags**: on posts, for the feed-card chips and Discover's category filters.
- **Direct messages**: the `mail` icon on User Profile.
- **Vault sync**: only if the local-only decision is revisited later — encrypted-blob backup/restore, keys never leaving the device.

## Suggested build order

1. Networking layer + dual-token auth storage (foundation everything else depends on).
2. Sign Up → Login → Profile Setup (account creation is the critical path).
3. Home Feed + Screenshot Detail (read paths, establishes Paging 3 + Coil patterns reused everywhere else).
4. Share Screen (Public Feed destination only) — the write path, reuses the same upload code the future Vault/local-save destination will need.
5. Follow/Followers/Following + User Profile.
6. Comments (add/delete) + post like/unlike.
7. Vault (independent local-only track — can be built in parallel by someone else, since it shares no backend surface with the above).
