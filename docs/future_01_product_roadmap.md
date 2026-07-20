# Screenshut Product Roadmap

## Purpose

This document defines the future product direction for Screenshut and prioritizes the capabilities
needed to grow it from a working social MVP into a safe, useful screenshot-sharing network.

Screenshut is a mobile-only social product. Its web application is exclusively an internal surface
for administrators, moderators, support staff, analysts, and engineering operations.

## Product boundaries

Every post must contain at least one screenshot. The following capabilities are intentionally out
of scope and must not be treated as missing features:

- Video uploads, playback, transcoding, or video analytics
- Live video, live audio, streaming, or audio rooms
- Stories or other ephemeral publishing formats
- Text-only posts
- A public web or desktop social client
- Creator streaming tools

The product should compete through screenshot-specific utility, privacy, safe interaction,
discovery, and moderation—not by copying every media format from larger social networks.

## Product principles

1. **Screenshots are the primary object.** Search, collections, accessibility, recommendations,
   and moderation should understand the contents of screenshots.
2. **Safety is a product feature.** Privacy, redaction, interaction controls, reporting, and
   moderation enforcement are part of the core experience.
3. **Users control their audience.** Users should understand who can see, mention, comment on,
   repost, or message them.
4. **Recommendations must be controllable.** Users need understandable feedback controls, and
   administrators need audited safety controls.
5. **Mobile is the customer product.** All user functionality is exposed through `/api/v1` and
   implemented by the mobile client.
6. **The web dashboard is internal.** It supports moderation, support, analytics, telemetry, and
   operations only.
7. **Prefer explainable systems first.** Build reliable event collection and formula-based
   personalization before considering learned ranking models.

## Current foundation

The existing backend already provides:

- Device enrollment and scoped installation credentials
- Email/password and Google, Facebook, and Apple authentication
- Two-factor authentication, recovery codes, and durable device sessions
- Profile and connected-account management
- Required-image carousel posts with captions
- Hashtags, mentions, follows, likes, saves, comments, replies, and reposts
- Block and mute controls
- User, post, and hashtag search
- Following and Explore feeds
- In-app and FCM notifications
- One-to-one text conversations
- User, post, and comment reports
- Account deletion and retention workflows
- Crash and diagnostic telemetry
- Admin user, report, device, and telemetry pages

## Roadmap overview

| Phase | Theme | Primary outcome |
|---|---|---|
| 0 | Correctness and enforcement | Existing safety controls behave consistently |
| 1 | Privacy and interaction controls | Users control visibility and contact |
| 2 | Moderation and administration | Staff can operate the social platform safely |
| 3 | Screenshot intelligence | Screenshots become searchable, accessible, and safer |
| 4 | Analytics and recommendation foundation | Product decisions and ranking use trustworthy data |
| 5 | Personalized discovery | Each user receives relevant, controllable recommendations |
| 6 | Organization and social depth | Collections and contextual screenshot features improve utility |
| 7 | Operational maturity | The service is observable, supportable, and resilient |

## Phase 0 — Correctness and enforcement

### Goals

- Make existing moderation, block, and mute behavior consistent across every surface.
- Close safety gaps before adding new distribution features.

### Deliverables

- Exclude suspended users and their content from public profiles, feeds, Explore, search, hashtag
  pages, follower lists, repost lists, and recommendations.
- Apply mute filtering to recommended feed items and Explore.
- Authorize reports against content the reporter is allowed to access.
- Prevent suspended or deleted users from being recommendation candidates.
- Define moderation states independently from login access.
- Add regression tests for all visibility rules.
- Correct README and API documentation so they describe the actual mobile/API/admin boundaries.

### Exit criteria

- A suspended account cannot create a session or appear on public mobile surfaces.
- Block and mute semantics are documented and covered across every relevant endpoint.
- Report validation cannot target inaccessible or already-purged content.

## Phase 1 — Privacy and interaction controls

### Goals

- Give users familiar account privacy and contact controls.
- Reduce unsolicited contact, spam, and harassment.

### Deliverables

#### Account privacy

- Public and private accounts
- Follow requests with approve, decline, cancel, and list operations
- Visibility enforcement for profiles, posts, followers, following, hashtags, search, and reposts
- Account-suggestion and email-discoverability preferences

#### Interaction controls

- Account defaults for who may comment, mention, and message
- Per-post comments-enabled and reposts-enabled controls
- Message requests for users who are not permitted to enter the main inbox directly
- Conversation reporting and user-side conversation removal
- Custom hidden words and basic abusive/spam text filtering

#### Notification controls

- Separate settings for comments, replies, mentions, follows, follow requests, reposts, messages,
  message requests, security alerts, and product updates
- Global push toggle
- Quiet hours with a stored timezone
- Notification aggregation for repeated activity on the same target

### Exit criteria

- Private-account content is inaccessible without an accepted follow relationship.
- Every inbound social interaction is evaluated against the recipient's settings.
- Message requests cannot silently enter the primary conversation list.

## Phase 2 — Moderation and administration

### Goals

- Turn the existing admin pages into a complete internal operations console.
- Ensure every sensitive administrative action is attributable and reversible where appropriate.

### Deliverables

#### Moderation cases

- Case status: open, investigating, actioned, dismissed, and appealed
- Priority, assignment, internal notes, timestamps, and resolution reason
- Multiple reports grouped around the same target
- Content preview and author/report history
- Actions to warn, restrict, remove, restore, suspend, ban, or exclude from recommendations
- Appeals and restoration workflow

#### Content administration

- Searchable screenshot/post browser
- Filters for status, author, date, hashtag, report count, and recommendation eligibility
- Screenshot preview, caption, engagement, reports, processing state, and moderation history
- Disable recommendation distribution without deleting content

#### User support

- Admin user detail page with authentication, sessions, devices, content, reports, moderation
  history, account state, and internal notes
- Scoped restrictions for posting, commenting, messaging, and recommendation eligibility
- Session revocation and password-reset assistance

#### Governance

- Roles for super administrators, moderators, support agents, telemetry viewers, analysts, and
  read-only auditors
- Permission checks for every internal route and mutation
- Immutable audit log containing actor, target, action, reason, before/after state, time, and
  related moderation case
- Step-up authentication for high-impact actions

### Exit criteria

- No moderation or user-management mutation occurs without an audit record.
- Moderators can inspect and resolve a report without using database or shell access.
- Support agents cannot access or perform moderator/engineering-only operations.

## Phase 3 — Screenshot intelligence

### Goals

- Build features that make Screenshut better at screenshots than general social networks.
- Improve safety, search, accessibility, and content understanding.

### Deliverables

#### OCR

- Asynchronous text extraction per screenshot
- OCR status, language, provider/version, and failure tracking
- Search index containing captions, hashtags, and safe OCR text
- User-visible OCR correction where appropriate

#### Accessibility

- Author-provided alt text per screenshot
- Optional OCR-assisted alt-text draft
- Caption language metadata
- API exposure of accessible descriptions

#### Privacy protection

- Detection of possible email addresses, phone numbers, credentials, payment data, QR codes,
  addresses, and other sensitive regions
- Mobile warning before publishing
- User confirmation or client-side redaction flow
- Explicit safety-result status without exposing detected secrets in logs or telemetry

#### Content understanding

- Perceptual hash for exact and near-duplicate detection
- User-selected screenshot category and optional source application
- Content-warning and spoiler/blur metadata
- Duplicate clusters for moderators and recommendation deduplication

### Exit criteria

- Users can search for permitted screenshot content using text contained in the image.
- Every screenshot can carry independent alt text.
- Sensitive-text detections never write raw secrets to application logs or telemetry.

## Phase 4 — Analytics and recommendation foundation

### Goals

- Measure content exposure and user satisfaction reliably.
- Create the data required for personalization and product decisions.

### Deliverables

- First-class impression events with viewer, post, author, surface, position, candidate source,
  session, and experiment identifiers
- Interaction events for opening, swiping, zooming, dwelling, saving, sharing, reposting, hiding,
  reporting, following, and "not interested"
- Idempotency and duplicate-event protection
- Retention policy and privacy review for behavioral events
- Daily aggregate tables for product and ranking metrics
- Feature flags and stable experiment assignment
- Analytics definitions for DAU, WAU, creator activity, engagement rates, hide/report rates,
  retention, and crash-free sessions
- Internal analytics dashboard

### Exit criteria

- Every recommendation metric has a trustworthy impression denominator.
- Events can be tied to a feed surface and candidate source without storing unnecessary content.
- Experiments can be enabled and evaluated without a mobile release for every server-side change.

## Phase 5 — Personalized discovery

### Goals

- Replace one global trending list with useful, diverse, and controllable recommendations.

### Deliverables

#### Candidate sources

- Followed accounts
- Followed hashtags and categories
- Two-hop social graph candidates
- Similar authors
- OCR/topic-similar screenshots
- Language and regional trends
- New-creator exploration
- Global trending fallback

#### Ranking

- Explainable formula using author affinity, topic affinity, freshness, quality engagement, social
  proof, diversity, prior exposure, negative feedback, safety, and manipulation risk
- Hard filtering for blocks, mutes, suspensions, privacy, age rules, and content eligibility
- Post-ranking diversity by author, topic, duplicate cluster, and candidate source
- Separate Following and For You feeds

#### User controls

- Not interested
- Hide screenshot
- Show fewer from this account
- Show fewer for a hashtag/category
- Reset recommendations
- Recommendation reason exposed to the client

#### Admin controls

- Trending and recommendation inspection
- Score explanation and candidate-source visibility
- Audited remove-from-recommendations action
- Emergency discovery disable switch
- Manipulation/anomaly review queue

### Exit criteria

- Recommendations vary meaningfully by user.
- Negative feedback changes future results.
- Safety and privacy filters run before ranking and cannot be bypassed by score.

## Phase 6 — Organization and social depth

### Goals

- Increase the long-term usefulness of saved screenshots and conversations around them.

### Deliverables

- Private saved-post collections
- Collection names, notes, ordering, and bulk organization
- Archive and recently deleted posts
- Pin selected posts to profile
- Structured context/source link separate from caption
- Screenshot annotations or region-based discussion, subject to product validation
- Compare/before-and-after presentation, subject to product validation
- Optional collaborative collections with explicit membership and permissions

### Exit criteria

- Saved screenshots can be organized without relying on a flat bookmarks feed.
- Archived content is private and restorable without being publicly distributed.

## Phase 7 — Operational maturity

### Goals

- Make the platform reliable and supportable as traffic and moderation volume grow.

### Deliverables

- Queue, scheduler, Redis, database, storage, mail, FCM, and security-outbox health views
- Failed-job and media-processing recovery tools
- Trending/recommendation job last-success and staleness alerts
- API latency, error, and rate-limit dashboards
- Crash grouping by fingerprint, release, OS, and affected-user count
- Crash resolution, assignment, notes, and fixed-release tracking
- Backup, restore, incident-response, and moderation-escalation runbooks
- Data-retention and deletion verification jobs
- OpenAPI contract and Android/server contract tests
- Load tests for feed, search, upload, notifications, and messaging

### Exit criteria

- Operators can detect stalled queues and scheduled jobs before users report them.
- Restore procedures are exercised and documented.
- Mobile/backend API compatibility is checked in CI.

## Priority summary

### Must have before broad public growth

1. Visibility enforcement for suspended accounts
2. Consistent block and mute behavior
3. Private accounts and follow requests
4. Message requests and interaction controls
5. Hidden words and basic spam controls
6. Moderation cases, content browser, admin roles, and audit log
7. Screenshot alt text and sensitive-information warning
8. Data export and explicit deletion lifecycle
9. Impression and recommendation-feedback tracking

### Strong differentiators

1. OCR-backed screenshot search
2. Safe redaction assistance
3. Saved screenshot collections
4. Duplicate and near-duplicate detection
5. Screenshot categories and source context
6. Personalized screenshot recommendations

### Explicit non-goals

- Video and live formats
- Stories
- Audio rooms
- Text-only publishing
- A public web social client
- Large-scale machine-learning ranking before trustworthy event data exists

## Success measures

Product success should be evaluated using a balanced set of measures:

- New-user activation: completes profile, follows accounts/topics, and views first feed
- Creator activation: publishes a first screenshot successfully
- Screenshot usefulness: save, collection-add, zoom, and meaningful-comment rates
- Discovery quality: follow-after-impression, save rate, hide rate, and report rate
- Retention: day 1, day 7, and day 30 active-user retention
- Safety: report prevalence, repeat-offender rate, median resolution time, and appeal outcomes
- Reliability: upload success, API error rate, queue delay, and crash-free sessions
- Diversity: unique authors/topics shown and exposure given to eligible new creators

Raw time spent must not be the only optimization target.
