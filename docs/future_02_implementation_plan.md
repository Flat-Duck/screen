# Screenshut Engineering Implementation Plan

## Purpose

This document converts `future_01_product_roadmap.md` into an actionable backend and admin-web
delivery plan. It focuses on the Laravel API, PostgreSQL/Redis data model, queues, tests, and the
internal Livewire dashboard. Mobile UI implementation is described only where it affects the API
contract.

## Delivery rules

- Preserve the requirement that every post contains at least one screenshot.
- Do not add video, live, Stories, text-only posts, or public web social pages.
- Keep `/api/v1` backward compatible wherever practical.
- Add new response fields in a backward-compatible way before making them required.
- Enforce privacy and safety in server queries, not only in the mobile client.
- Use database constraints for durable invariants and services/policies for contextual rules.
- Queue slow OCR, image analysis, notification, and aggregation work.
- Record admin actions in the same transaction as the state change when possible.
- Add feature flags for changes that materially affect feed distribution or visibility.
- Add tests with each vertical slice; do not postpone safety tests to the end of a phase.

## Recommended delivery structure

Each milestone should be delivered as a vertical slice:

1. Migration and model changes
2. Policy/service behavior
3. API request/resource changes
4. Admin web changes where relevant
5. Queue/scheduler changes
6. Feature and concurrency tests
7. API documentation and mobile handoff
8. Metrics and rollout guardrails

## Milestone 0.1 — Visibility policy foundation

### Objective

Centralize who and what can appear on public API surfaces.

### Data model

Add explicit user moderation fields rather than overloading `is_active`:

```text
users.visibility_state       enum: visible, limited, hidden
users.moderation_state       enum: clear, restricted, suspended, banned
users.moderated_at           nullable timestamp
users.moderation_reason      nullable text
```

Keep `is_active` temporarily for authentication compatibility, then consider replacing it with a
dedicated login state in a later migration.

### Application changes

- Introduce a reusable visibility scope/service for public user and content queries.
- Apply it to feed, Explore, search, hashtags, profiles, follower lists, following lists, reposts,
  comments, mentions, notifications, and recommendation candidates.
- Define whether `limited` content is profile-only or visible only to existing followers.
- Ensure hidden/suspended authors cannot be newly followed or messaged.
- Apply mute filtering to discovery candidates and Explore.
- Return `404` for inaccessible public resources where revealing existence is undesirable.

### Tests

- Matrix tests across viewer relationship × author state × endpoint.
- Block and mute regression tests for every listing surface.
- Existing-session revocation tests when an account is suspended.
- Recommendation job tests excluding ineligible users/content.

### Acceptance criteria

- One shared visibility policy determines eligibility across all public API queries.
- Suspending an author immediately removes their content from public distribution.
- Reinstating an eligible author restores visibility without recreating content.

## Milestone 0.2 — Report authorization and moderation state

### Objective

Make reports reflect accessible content and prepare them for case management.

### Data model

Extend reports with:

```text
reports.priority             enum: low, normal, high, urgent
reports.assigned_to          nullable foreign key users
reports.opened_at            timestamp
reports.actioned_at          nullable timestamp
reports.appealed_at          nullable timestamp
reports.case_key             indexed string
```

Create `moderation_notes` with case/report, author, body, and timestamps.

### Application changes

- Replace table-only existence validation with target resolution plus visibility authorization.
- Group duplicate reports by stable target case key.
- Preserve individual reporter records while presenting one admin case.
- Add state transitions with explicit allowed-transition rules.
- Add reportable message/conversation support only after message privacy work is available.

### Tests

- Cannot report blocked/inaccessible/private content unless policy explicitly permits it.
- Duplicate reports group correctly without losing reporter information.
- Invalid state transitions fail.
- Concurrent reports do not create duplicate cases.

## Milestone 1.1 — Private accounts and follow requests

**Status: implemented 2026-07-20.** The existing `/users/{user}/follow` endpoint is the unified
mobile contract: it returns `204` for a public-account follow and `202` with
`data.status=requested` plus `request_id` for a private account. Deleting that follow also cancels
any pending request. The explicit follow-request create/cancel routes remain available as aliases.

### Objective

Support public/private accounts without weakening existing follow behavior.

### Data model

Add:

```text
users.account_visibility     enum: public, private
follow_requests
  id
  requester_id
  target_id
  status                     enum: pending, accepted, declined, cancelled
  responded_at
  timestamps
```

Constraints:

- Unique active request per requester/target pair
- No self-request
- Indexed target/status and requester/status

### API

Add:

```text
POST   /api/v1/users/{user}/follow-requests
DELETE /api/v1/users/{user}/follow-requests
GET    /api/v1/follow-requests/incoming
GET    /api/v1/follow-requests/outgoing
POST   /api/v1/follow-requests/{request}/accept
POST   /api/v1/follow-requests/{request}/decline
```

The existing follow endpoint is the unified operation described above.

### Visibility rules

- Public account: existing behavior.
- Private account: only accepted followers see posts and reposts.
- Profile summary may remain visible, but content/count exposure must be explicitly decided.
- Reposts of private content must not expose it outside the approved audience.
- Search may return the account while withholding private content.
- Existing followers remain accepted when an account switches to private.

### Notifications

- New follow request
- Follow request accepted
- Optional declined notification should default to absent

### Tests

- Privacy matrix for profile, posts, comments, reposts, search, hashtags, saves, and direct URLs.
- Concurrent accept/cancel behavior.
- Block automatically cancels pending requests and removes accepted follows.
- Account deletion cleans up requests.

## Milestone 1.2 — Interaction permissions

**Status: implemented 2026-07-20.** Audience checks are centralized in
`InteractionPermissionService` and enforced for new comments, mentions, reposts, conversation
creation, and message sends. Existing comments remain readable when comments are disabled;
historical reposts remain until the reposter removes them. Messaging defaults to `everyone` for
backward compatibility and can be tightened now; Milestone 1.3 will change unknown senders into
message requests instead of rejecting them.

### Objective

Control who may comment, mention, repost, or message a user.

### Settings schema

Extend the JSON settings contract with validated enums:

```json
{
  "interactions": {
    "comments_from": "everyone",
    "mentions_from": "everyone",
    "messages_from": "following",
    "reposts_from": "everyone",
    "reposts_allowed": true
  }
}
```

Supported audience values should be centralized in an enum:

- `everyone`
- `followers`
- `following`
- `mutuals`
- `no_one`

### Post model

Add snapshot-level overrides:

```text
posts.comments_enabled
posts.reposts_enabled
```

### Application changes

- Create `InteractionPermissionService` as the single evaluation path.
- Enforce settings before writes, notification dispatch, and mention synchronization.
- Define existing-comment behavior when comments are disabled.
- Prevent repost creation when disabled; do not necessarily delete historical repost records
  without a separate product decision.

### Tests

- Full audience-value matrix.
- Private-account and block behavior take precedence over permissive interaction settings.
- Settings changes affect new actions immediately.

## Milestone 1.3 — Message requests

**Status: implemented 2026-07-20.** New contacts who do not satisfy `messages_from` create a
`requested` conversation with one required initial message, unless the recipient selected
`no_one`. Requests stay outside the primary inbox until accepted. Rejection starts a configurable
30-day cooldown; deletion is participant-local hiding so it cannot erase cooldown or moderation
history. Accepted conversations remain active even if the follow graph later changes.

### Objective

Prevent unsolicited users from entering the primary inbox.

### Data model

Extend conversations:

```text
conversations.state          enum: active, requested, rejected
conversations.requested_by   nullable foreign key users
conversations.accepted_at    nullable timestamp
conversations.rejected_at    nullable timestamp
conversation_participants.hidden_at nullable timestamp
```

Consider a participant-local conversation state if users need independent archive/delete behavior.

### API

Add:

```text
GET    /api/v1/message-requests
POST   /api/v1/conversations/{conversation}/accept
POST   /api/v1/conversations/{conversation}/reject
DELETE /api/v1/conversations/{conversation}       participant-local hide
POST   /api/v1/conversations/{conversation}/report
```

### Rules

- Allowed contacts create active conversations.
- Other eligible contacts create a request with a bounded initial message.
- Rejected requesters cannot immediately create another request.
- Blocking closes or hides the conversation according to documented semantics.
- Request messages do not expose read receipts or activity information.

### Tests

- Contact-permission matrix.
- Repeated request spam prevention.
- Block/reject race conditions.
- Notification settings for message requests.

## Milestone 1.4 — Hidden words and notification controls

**Status: implemented 2026-07-20.** Hidden terms have encrypted originals, normalized hashes for
deduplication, Unicode/evasion-aware matching, ownership-safe CRUD APIs, and a 100-term limit.
Comment/message filter matches are participant-local markers: API resources redact the body only
for the affected user while preserving the original content for moderation. Offensive filtering
uses a deploy-configured policy lexicon. Push delivery now supports a global switch, granular
categories, timezone-aware quiet hours, and a security-alert bypass marker.

### Objective

Give users practical abuse and interruption controls.

### Data model

Create `user_hidden_terms` with normalized value, encrypted/original value if needed, type, and
timestamps. Avoid storing sensitive terms in logs.

Extend settings:

```json
{
  "content_filters": {
    "hide_offensive_comments": true,
    "hide_offensive_messages": true
  },
  "notifications": {
    "push_enabled": true,
    "likes": true,
    "comments": true,
    "replies": true,
    "mentions": true,
    "follows": true,
    "follow_requests": true,
    "reposts": true,
    "messages": true,
    "message_requests": true,
    "product_updates": false,
    "quiet_hours": {
      "enabled": false,
      "start": "22:00",
      "end": "07:00",
      "timezone": "UTC"
    }
  }
}
```

### Application changes

- Normalize Unicode and common evasion patterns.
- Mark filtered items rather than destructively deleting them.
- Apply quiet hours at push-send time while retaining in-app notifications.
- Security alerts bypass user-disabled social notification categories.
- Aggregate repeat likes/comments where appropriate.

### Tests

- Unicode and case normalization.
- Quiet-hour boundary and timezone tests.
- Security alerts remain deliverable.
- Filtered content remains available to authorized moderators.

## Milestone 2.1 — Admin roles and audit log

### Objective

Secure internal operations before expanding moderator capabilities.

### Data model

Use role/permission tables or a small first-party enum-based implementation:

```text
roles
permissions
role_user
permission_role
admin_audit_logs
```

Audit records should include:

```text
actor_id
action
target_type
target_id
reason
before_state JSON
after_state JSON
request_id
ip_hash or appropriately protected IP
created_at
```

Audit records must be append-only through the application.

### Application changes

- Replace the single `is_admin` authorization boundary with named permissions.
- Retain a migration path for existing admins.
- Require a reason for destructive or visibility-changing actions.
- Require step-up authentication for role changes, bans, and permanent destruction.

### Tests

- Permission matrix by role.
- Every admin mutation creates exactly one audit entry.
- Failed mutations do not create misleading success audit records.
- Administrators cannot remove their own final super-admin access.

## Milestone 2.2 — Moderation cases and content browser

**Status: implemented 2026-07-20.** Pending reports are grouped into concurrency-safe open cases,
including a bounded migration backfill. The admin workspace provides case filters, assignment,
priority, internal notes, controlled transitions, warnings, suspension/ban, content removal and
restoration, and recommendation eligibility. The content browser includes private and soft-deleted
screenshots. Moderation pages and authenticated media previews use no-store headers, and every
mutation requires a reason and writes an audit entry.

### Objective

Provide moderators with complete context and controlled actions.

### Admin pages

- `/moderation/cases`
- `/moderation/cases/{case}`
- `/content`
- `/content/{post}`

### Capabilities

- Search, filter, sort, and paginate cases/content.
- Preview every screenshot safely.
- Show report totals, reasons, author history, OCR when available, engagement, and duplicate group.
- Assign cases and add internal notes.
- Warn, restrict, remove, restore, suspend, ban, and remove from recommendations.
- Record every action and reason.

### Safety requirements

- Do not render untrusted OCR/caption content as raw HTML.
- Use signed or controlled media URLs for admin access if media becomes private.
- Prevent accidental caching of sensitive moderation pages.

### Tests

- Authorization and Livewire action tests.
- State-transition tests.
- Audit-log assertions.
- Soft-deleted and private content remains available only to authorized staff.

## Milestone 2.3 — Admin user detail and scoped restrictions

**Status: implemented 2026-07-20.** The admin user detail combines account state, social counts,
recent screenshots, devices, sessions, connected providers, reports, warnings, restrictions,
moderation audit history, and support notes. Posting, commenting, messaging, recommendation, and
login restrictions are independently and immediately enforced. Restrictions support future starts,
automatic time-based expiry, overlap, extension, revocation, optional permanent duration, case
linkage, mandatory reasons, and audit records. Login restrictions revoke current sessions.

### Objective

Support users without relying on full account suspension.

### Data model

Create account restrictions:

```text
user_restrictions
  user_id
  type             posting, commenting, messaging, recommendation, login
  starts_at
  ends_at
  reason
  moderation_case_id
  created_by
  revoked_at
```

### Admin page

`/users/{user}` should show profile, account state, sessions, devices, social counts, content,
reports, restrictions, moderation history, connected-account summary, and support notes.

### Tests

- Each restriction affects only its intended capability.
- Expired restrictions stop applying without manual cleanup.
- Restriction creation/revocation is audited.

## Milestone 3.1 — Alt text and screenshot metadata

**Status: implemented 2026-07-20.** Post creation accepts position-aligned `media_metadata`,
owners can update an individual image's alt text without replacing it, and post resources expose
safe structured context while withholding OCR text and perceptual hashes. Active categories are
available through a dedicated mobile discovery endpoint.

### Objective

Make screenshots accessible and provide structured context.

### Data model

Extend `post_media`:

```text
alt_text                  nullable text
ocr_text                  nullable text
ocr_language              nullable string
ocr_status                pending, processing, ready, failed
ocr_version               nullable string
perceptual_hash           nullable indexed string
safety_status             pending, clear, warning, failed
```

Extend posts with optional structured context:

```text
category_id
source_application
source_url
content_warning
```

### API

- Accept ordered media metadata alongside uploads.
- Permit the owner to update alt text without replacing the image.
- Return alt text, content warning, and safe structured context in resources.
- Do not expose OCR text by default until privacy behavior is approved.

### Tests

- Media metadata remains aligned with carousel positions.
- Alt-text authorization and length limits.
- URL validation and safe serialization.

## Milestone 3.2 — OCR and duplicate processing

**Status: implemented 2026-07-20.** New media dispatches independent, retryable OCR and
perceptual-hash jobs after the post transaction commits; successful OCR dispatches screenshot
safety evaluation. Provider-version checks make jobs idempotent and allow deliberate
reprocessing after analyzer upgrades. OCR is encrypted at rest, excluded from public resources
and search, bounded to 50,000 characters, and deleted with its media. Duplicate detection stores
an indexed 64-bit difference hash; all processors record pending/processing/ready-or-result/failed
states without logging extracted text.

### Objective

Extract searchable text and detect repeated screenshots asynchronously.

### Jobs

- `ExtractPostMediaText`
- `ComputePostMediaPerceptualHash`
- `EvaluateScreenshotSafety`
- Retry/backoff and failure-state handling consistent with thumbnail jobs

### Search

Use Laravel Scout with its PostgreSQL `database` driver. Posts search the denormalized
`posts.searchable_text` document (caption now, approved OCR/category/source text later), while
usernames and hashtags use prefix matching. Preserve a separate latest-order mode alongside
Scout's relevance-ordered results.

Keep SQLite-compatible service boundaries for tests, but allow PostgreSQL-specific integration
tests for production search behavior.

### Privacy

- Never include OCR text or detected secrets in exception messages.
- Define whether raw OCR is retained, encrypted, or discarded after indexing.
- Delete OCR/index data with its media and account lifecycle.

### Tests

- Job idempotency and retries.
- Search permission filtering for private/hidden content.
- Duplicate-cluster consistency.
- Deletion removes derived data.

## Milestone 3.3 — Sensitive-information warning contract

**Status: implemented 2026-07-20.** The mobile-safe creation path now stages screenshots under an
owner-scoped UUID token for 30 minutes, processes them before any `Post` exists, and returns only
finding categories plus normalized regions. Warning analyses require explicit acknowledgement in
the atomic publish request; the post records the acknowledgement timestamp and analyzer version.
Clients redact locally and submit a new analysis, explicitly continue, or cancel. Expired,
cancelled, and abandoned staging files are removed by the existing media cleanup workflow. The
original direct-post endpoint remains temporarily available for backward compatibility while
mobile clients migrate to the staged contract.

### Objective

Let the mobile client warn and help users redact before final publication.

### Recommended flow

1. Mobile performs fast local detection where possible.
2. Backend stages screenshots and performs bounded safety analysis.
3. Backend returns categories and region coordinates, not detected secret text.
4. Mobile asks the user to redact, cancel, or explicitly continue.
5. Final post creation records acknowledgement and analysis version.

### API options

- Extend the existing staged-media flow, or
- Add `/api/v1/media/analyze` followed by a short-lived upload token.

Avoid creating a publicly visible post before the warning decision is complete.

### Tests

- Analysis token ownership and expiration.
- No cross-user staged-media access.
- Raw detected values absent from logs, telemetry, and responses.
- Cleanup of abandoned analyses.

## Milestone 4.1 — Impression and interaction events

**Status: implemented 2026-07-20.** `/api/v1/analytics/content-events` accepts transactional,
idempotent batches of up to 50 allow-listed behavioral events and derives user, device, and
session identity from the active mobile token. Post visibility, author ownership, block state,
event time, event-specific metadata, payload size, and rate limits are enforced before insertion.
Analytics rows never mutate authoritative social tables. Raw events have a documented 90-day
retention window and are removed by a daily scheduled command; longer-lived aggregate tables are
deferred to Milestone 4.2.

### Objective

Create reliable behavioral data without misusing crash telemetry.

### Data model

Use append-oriented event storage with controlled event names:

```text
content_events
  event_uuid
  user_id
  device_id
  session_id
  post_id
  author_id
  surface
  event_type
  position
  candidate_source
  request_id
  experiment_assignments JSON
  occurred_at
  received_at
  metadata JSON
```

Candidate event types:

- impression
- open
- carousel_swipe
- zoom
- dwell
- like
- comment
- save
- collection_add
- repost
- share
- profile_open
- follow_author
- hide
- not_interested
- report

### API

Add a bounded, authenticated batch endpoint separate from diagnostic telemetry:

```text
POST /api/v1/analytics/content-events
```

### Requirements

- Idempotent event UUIDs
- Strict event and metadata allowlists
- Batch and payload limits
- Server-side user/device identity
- Retention and aggregation policies
- Protection against clients forging authoritative engagement counters

### Tests

- Duplicate batches.
- Invalid user/session/post relationships.
- Size and rate limits.
- Redaction and retention.

## Milestone 4.2 — Aggregates and internal analytics

**Status: implemented 2026-07-20.** Rebuildable UTC aggregates now cover daily product activity,
post exposure/interaction, user-author affinity, user-category affinity, recommendation feedback,
and day-0 through day-30 retention cohorts. The scheduled command rebuilds the current partial day
hourly and the completed prior day after midnight, with bounded date/range backfills for repair.
The admin dashboard now presents the required overview, conversion, safety, retention,
moderation, and crash-free metrics with explicit partial-day labeling. Metric definitions and
affinity weights live in `docs/future_03_analytics_metrics.md`.

### Objective

Turn events into product and recommendation metrics.

### Data model/jobs

- Daily user activity aggregates
- Daily post exposure/interaction aggregates
- User-author affinity aggregates
- User-topic affinity aggregates
- Recommendation feedback aggregates
- Scheduled aggregation with repair/backfill commands

### Dashboard

Add overview cards and charts for:

- DAU/WAU
- Registrations
- Active creators
- Screenshots published
- Impression-to-open/save/follow rates
- Hide/report rates
- Retention cohorts
- Moderation backlog and age
- Crash-free sessions

### Requirements

- Document every metric definition.
- Use UTC for aggregation boundaries and localize only presentation.
- Mark partial/current-day data clearly.

## Milestone 4.3 — Feature flags and experiments

**Status: implemented (2026-07-20).** Server-managed flags, deterministic versioned experiment
assignments, exposure propagation, kill switches, audited configuration commands, protected safety
namespaces, and a read-only admin status page are now available. See
`docs/future_04_feature_flags_experiments.md` for the operational and mobile contract.

### Objective

Roll out feed changes safely.

### Capabilities

- Server-managed flags
- Stable deterministic assignment
- Explicit control and treatment variants
- Start/end windows
- Kill switch
- Assignment included in feed response/events
- Admin read-only experiment status initially

### Guardrails

- Privacy and moderation behavior must not be experimentable to weaker states.
- Log configuration changes in the admin audit log.

## Milestone 5.1 — Candidate generation

**Status: implemented (2026-07-20).** Nine bounded generators now cover the eight planned source
families (hashtags and categories are separate), with centralized hard eligibility, deduplication,
source provenance, versioned Redis hot pools, database fallback, and scheduled TTL refresh. See
`docs/future_05_candidate_generation.md`.

### Objective

Produce several bounded recommendation pools.

### Candidate sources

Implement independently behind interfaces:

- In-network recent posts
- Followed hashtags/categories
- Global trending
- Language/region trending
- Two-hop author graph
- Similar author
- Similar OCR/topic
- New creator exploration

Each candidate must include source, source score, generated time, and eligibility metadata.

### Storage

- Redis sorted sets for disposable hot pools
- PostgreSQL for durable affinity/eligibility data
- Version Redis keys so ranking changes can be rolled back safely

### Tests

- Candidate eligibility and privacy.
- Redis failure fallback.
- Stale-key expiration.
- Deterministic test fixtures for each source.

## Milestone 5.2 — Personalized scoring and mixing

**Status: implemented (2026-07-20).** Candidate feature hydration, deterministic explainable
scoring, bounded diversity/source mixing, persisted feed-session pagination, recommendation
metadata, and explicit Following/For You APIs are available. See
`docs/future_06_personalized_ranking.md` for the API and operational contract.

### Objective

Rank candidates with an explainable non-ML model.

### Initial formula inputs

- Author affinity
- Topic/category affinity
- Freshness
- Unique-user engagement quality
- Save/share/repost weight
- Social proof
- New-creator exploration boost
- Already-seen penalty
- Author/topic repetition penalty
- Hide/not-interested/report penalty
- Safety/manipulation penalty

### Pipeline

1. Generate candidates.
2. Apply hard privacy/safety/eligibility filters.
3. Hydrate features.
4. Score.
5. Deduplicate.
6. Diversify.
7. Mix sources.
8. Return a stable feed cursor plus recommendation/request identifiers.

Do not splice unrelated records into a database cursor without a feed-specific pagination design.
Use a generated feed page/session token if necessary.

### API

Provide explicit surfaces:

```text
GET /api/v1/feed/following
GET /api/v1/feed/for-you
```

The existing `/feed` endpoint can remain an alias during migration.

Include non-sensitive recommendation metadata:

```json
{
  "recommendation": {
    "request_id": "...",
    "source": "followed_hashtag",
    "reason": "Because you follow #design"
  }
}
```

### Tests

- Deterministic scoring.
- Hard-filter precedence.
- Diversity limits.
- Stable pagination without duplicate/omitted items.
- Cold-start and Redis-outage fallbacks.

## Milestone 5.3 — Recommendation feedback and administration

**Status: implemented (2026-07-20).** Durable user-local post and target feedback, recommendation
profile reset, immediate session invalidation, global audited exclusions, score diagnostics,
anomaly indicators, and an independent For You serving kill switch are available. See
`docs/future_07_recommendation_feedback_admin.md`.

### API

Add:

```text
POST   /api/v1/posts/{post}/not-interested
DELETE /api/v1/posts/{post}/not-interested
POST   /api/v1/posts/{post}/hide
POST   /api/v1/users/{user}/show-fewer
POST   /api/v1/hashtags/{hashtag}/show-fewer
DELETE /api/v1/recommendations/profile
```

### Admin web

- Inspect current trending and candidate pools.
- View eligibility and score components.
- Remove content from recommendations with reason and expiration.
- View anomaly/manipulation indicators.
- Disable recommendation serving globally without breaking Following feed.

### Tests

- Feedback affects only the acting user unless it is an administrative action.
- Reset removes personalization state but not necessary account/security history.
- Admin actions are permission-gated and audited.

## Milestone 6.1 — Saved screenshot collections

**Status: implemented (2026-07-21).** Private owner-only collections, notes, transactional ordering,
optimistic versions, automatic saving, multi-collection membership, visibility-safe listing, and
global-unsave cleanup are available. See `docs/future_08_saved_collections.md`.

### Data model

```text
collections
  user_id
  name
  description
  position
  visibility            private initially

collection_items
  collection_id
  post_id
  note
  position
```

### API

```text
GET    /api/v1/collections
POST   /api/v1/collections
PATCH  /api/v1/collections/{collection}
DELETE /api/v1/collections/{collection}
GET    /api/v1/collections/{collection}/posts
POST   /api/v1/collections/{collection}/posts/{post}
PATCH  /api/v1/collections/{collection}/posts/{post}
DELETE /api/v1/collections/{collection}/posts/{post}
```

### Rules

- Collections are private in the first release.
- Collection access must not override post privacy or deletion.
- Decide whether deleted/inaccessible posts leave a tombstone or disappear.

Decision: inaccessible posts are omitted from API results without a tombstone while membership is
retained; permanent post deletion removes membership through database cascading.

### Tests

- Ownership authorization.
- Unique membership.
- Ordering and concurrent updates.
- Private-post visibility changes.

## Milestone 6.2 — Archive and recently deleted

**Status: implemented (2026-07-21).** Owner-only archive, retained deletion, restoration, and
step-up-protected permanent deletion are available. See `docs/future_09_archive_recently_deleted.md`.

### Objective

Separate private archiving from deletion and retention.

### Data model

```text
posts.archived_at
posts.deleted_at          existing
```

### API

```text
POST   /api/v1/posts/{post}/archive
DELETE /api/v1/posts/{post}/archive
GET    /api/v1/archived-posts
GET    /api/v1/recently-deleted-posts
POST   /api/v1/posts/{post}/restore
DELETE /api/v1/posts/{post}/permanently-delete
```

Permanent deletion should require step-up authentication when appropriate and must coordinate
media cleanup safely.

## Milestone 7.1 — Operations dashboard

**Status: implemented (2026-07-21).** The role-gated dashboard, minute-level dependency snapshots,
durable scheduled-task runs, bounded API metrics, workflow backlogs, storage totals, and app-version
adoption are available. See `docs/future_10_operations_dashboard.md`.

### Objective

Expose the state of every production dependency and background workflow.

### Dashboard sections

- Queue backlog and failed jobs by queue
- Scheduler heartbeat and last successful command run
- Redis, database, storage, mail, and FCM health
- Media-processing and cleanup failures
- Security-outbox backlog
- API latency/error/rate-limit charts
- Storage usage and growth
- App-version adoption

### Implementation

- Add durable scheduled-task heartbeat records.
- Add bounded health snapshots rather than expensive live scans on every dashboard request.
- Protect secrets and internal exception details by role.

## Milestone 7.2 — Telemetry triage

**Status: implemented (2026-07-21).** Durable fingerprint groups, filtered occurrence analysis,
representative samples, assignment, audited notes and state transitions, and fixed-release tracking
are available. See `docs/future_11_crash_triage.md`.

### Objective

Turn raw crashes into an engineering workflow.

### Data model

Create crash groups keyed by fingerprint with:

- Status: open, investigating, resolved, ignored
- First/last seen
- Occurrence and affected-user counts
- Assigned administrator/engineer
- Fixed app version
- Notes

### Admin web

- Crash-group list and detail pages
- Release/OS/device filters
- Occurrence chart
- Sample event inspection
- Resolve/reopen/ignore actions

## Milestone 7.3 — Contracts, load tests, and runbooks

**Status: implemented (2026-07-21).** Route-complete OpenAPI export and CI drift checks, validated
mobile models, opt-in k6 scenarios, and production incident/drill runbooks are available. See
`docs/future_12_release_readiness.md`.

### Deliverables

- OpenAPI description for `/api/v1`
- Generated or validated mobile API models
- Contract tests for telemetry and social payload names
- Load scenarios for upload, feed, Explore, search, notifications, messaging, and analytics events
- Backup/restore drill
- Queue/scheduler outage runbook
- Moderation escalation runbook
- Account compromise and data-deletion runbooks

## Cross-cutting test strategy

Every milestone should include the relevant test categories:

### Feature tests

- Authentication and token type
- Authorization and resource visibility
- Validation and error response shape
- Pagination and filtering
- Notification side effects
- Account deletion/restore behavior

### Concurrency tests

- Follow-request acceptance/cancellation
- Duplicate reports/case grouping
- Collection membership
- Event idempotency
- Admin state transitions

Run concurrency tests against PostgreSQL, not only SQLite.

### Queue tests

- Idempotency
- Retry/backoff
- Failure state
- Deleted-resource handling
- After-commit dispatch behavior

### Security tests

- Device tokens cannot access user routes
- Users cannot access private or hidden resources by direct ID
- Admin roles cannot exceed their permissions
- Audit records cannot be changed through application endpoints
- OCR/safety processing never leaks detected secrets

### Performance tests

- Query count ceilings for feed and list endpoints
- Index-aware search tests on PostgreSQL
- Feed latency with large follow graphs
- Admin list latency with large report/content tables
- Batch analytics ingestion throughput

## Migration and compatibility strategy

1. Add nullable/defaulted fields first.
2. Deploy code that reads old and new states safely.
3. Backfill in bounded jobs or commands.
4. Add constraints after verifying the backfill.
5. Change mobile behavior behind server flags.
6. Remove obsolete fields/endpoints only after supported mobile versions have migrated.

For settings, continue returning defaults for missing keys so older accounts require no backfill.
Version settings semantics in code and validate unknown keys strictly.

## Suggested implementation order

Execute in this order because later work depends on earlier policy and governance foundations:

1. Visibility policy and current safety corrections
2. Report authorization and moderation states
3. Admin roles and audit log
4. Private accounts and follow requests
5. Interaction permissions and message requests
6. Hidden words and notification expansion
7. Moderation cases, content browser, and user detail
8. Alt text and screenshot metadata
9. OCR, duplicate detection, and sensitive-information workflow
10. Impression and interaction events
11. Aggregates, analytics dashboard, and experiments
12. Candidate sources and personalized feed
13. Recommendation feedback/admin controls
14. Collections, archive, and recently deleted
15. Operations dashboard, telemetry triage, contracts, and load testing

## Definition of done for a milestone

A milestone is complete only when:

- Database constraints and indexes are present where required.
- Server authorization is enforced on every read and write path.
- API responses and errors are documented with examples.
- Existing supported mobile behavior remains compatible or is feature-flagged.
- Feature, security, and relevant concurrency tests pass.
- Queue jobs are idempotent and observable.
- Admin mutations are permission-gated and audited.
- Metrics exist to evaluate rollout quality.
- Retention and deletion behavior is defined for new data.
- Operational and mobile handoff notes are updated.

## First delivery batch

The recommended first engineering batch is deliberately narrow:

1. Add centralized public visibility rules.
2. Hide suspended authors/content across all mobile surfaces.
3. Apply mute filtering to discovery and Explore.
4. Enforce report-target visibility.
5. Add named admin permissions and an audit log.
6. Update documentation and add regression tests.

This batch fixes current correctness and safety risks while creating the foundation needed for
private accounts, richer moderation, and personalized discovery.
