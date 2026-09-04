# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A screenshot-sharing social platform whose Android installation, user sessions, FCM registration,
and crash telemetry share one device domain. `Device` tracks the current nullable user,
`DeviceSession` preserves immutable login history, and crash telemetry is one `/api/v1` feature.

## Commands

```bash
composer dev          # runs `php artisan dev` — serves app + queue + vite together
composer lint         # pint --parallel (auto-fixes style)
composer lint:check   # pint --parallel --test (CI mode, no changes)
composer types:check  # phpstan analyse (larastan, level 7)
composer test         # config:clear + lint:check + types:check + php artisan test
php artisan test                                   # run the full suite
php artisan test --filter=test_registering_a_new_device_creates_it_and_returns_a_token
php artisan test tests/Feature/TelemetryApiTest.php # run a single test file
```

Installing dependencies requires Flux Pro composer credentials
(`composer config http-basic.composer.fluxui.dev <user> <license-key>`) — see `.github/workflows/*.yml`
for how CI supplies these.

## Architecture

### Two authenticatable principals, one guard family

`Device` (app/Models/Device.php) extends the same `Authenticatable` base as `User` and uses
`HasApiTokens` (Sanctum). This lets `auth:sanctum` resolve a `Device` as `$request->user()` on API
routes exactly like it would a `User` on web routes — the two never mix because a Sanctum token
belongs to exactly one tokenable model. Don't assume `$request->user()` on API routes is a `User`;
in `TelemetryController` it is always a `Device`.

### Canonical device lifecycle

Android first calls `POST /api/v1/devices/enroll`, then retains the restricted Device credential
for FCM and telemetry. User authentication requires that Device credential and returns a separate
User token plus a durable `session_id`.

- `POST /api/v1/devices/enroll` (throttled 20/min): creates a `Device` by `device_uuid` and mints
  a fresh Sanctum token — unauthenticated, since that's the only way a device can get a token in
  the first place. Re-registering an **existing** `device_uuid` is different: it always requires
  that exact device's current token via `Authorization: Bearer` to rotate it (proof of possession,
  not just knowledge of the UUID) — otherwise anyone who learns/guesses a `device_uuid` could
  silently steal that device's identity, since tokens are hashed at rest and the old one gets
  deleted unconditionally. This holds even for a device with **no live token** (e.g. deliberately
  revoked by support after a compromise) — a tokenless device is not up for grabs by whoever asks
  next, since that would let anyone silently reclaim exactly the kind of device a revocation was
  meant to lock out. There is deliberately no unauthenticated recovery path for an existing
  `device_uuid` that lost its token. Practically: a real reinstall/cleared-app-data event wipes the
  token *and* the locally-stored `device_uuid` together (the common case), so the client just
  generates a new UUID and registers fresh, same as any new device — this restriction only ever
  bites a `device_uuid` that somehow persists independently of the token, or a genuine attack
  attempt.
- `POST /api/v1/telemetry/events` (`auth:sanctum`, Device-only, throttled 120/min): batch-ingests
  events. Identity comes solely from the Device token. Optional per-event session UUIDs are
  server-validated and snapshot `user_id`/`device_session_id`. Insertion is
  `firstOrCreate` keyed on `event_uuid`, making resends after an ambiguous network failure safe.
- `TelemetryEvent` has three `kind`s (`KIND_EVENT`, `KIND_ERROR`, `KIND_FATAL_CRASH`); error-specific
  columns (`exception_class`, `stack_trace`, etc.) are nullable and populated only for the latter two
  — they're 1:1 with the row, not a separate table/relation. `scopeCrashes()` filters `kind != event`.
- Request validation in `StoreTelemetryEventsRequest` intentionally mirrors the Android client's
  `TelemetryBatchRequest`/`TelemetryEventPayload` field names exactly — keep them in sync if the
  client payload shape changes.
- Requests are limited to 50 events and 512 KB. Context is redacted, stack traces are truncated to
  4000 characters, and crashes receive a release-indexed fingerprint.

### Dashboard (web, session auth)

`DashboardController`, `DeviceController`, `EventController` render Blade views; the interactive
searchable/sortable/paginated tables themselves are separate Livewire components
(`App\Livewire\DevicesTable`, `App\Livewire\EventsTable`) rendered inside those views — not the
controllers. Both use `#[Url]`-bound public properties for search/sort state and reset pagination
`updating*` hooks fire.

Gated by the `viewTelemetry` Gate (`AppServiceProvider::configureGates()`), which checks
`User::$is_admin` — not just `auth`+`verified`. This matters because `User` is *also* the social
API's end-user principal (Sanctum, mobile app): without the Gate, any registered mobile-app user
could browse every device's crash/event history simply by logging into the web dashboard.
`is_admin` is deliberately absent from `User`'s `#[Fillable]` attribute (never mass-assignable);
grant/revoke it via `php artisan users:make-admin {email} [--revoke]`.

### Moderation alerting and tag control

- **Detection never applies a consequence.** `moderation:detect-alerts` (scheduled every five
  minutes) runs the detectors in `config('moderation.alerts.detectors')` and raises
  `ModerationAlert` rows; every consequence still goes through `ModerationCaseService` /
  `HashtagModerationService`, which demand a written reason and write an `AdminAuditLog`.
  This is a deliberate product decision, not an unfinished feature — don't "improve" a
  detector by having it hide, de-rank, or suspend anything.
- Detectors are isolated from one another: one throwing is logged and skipped, and the
  command still exits 0, because the failure that matters is "nobody was told", not "one
  rule was missing". The trending tripwire's post half additionally fails open when Redis is
  unreachable, same as `FeedService`.
- `ModerationAlert::open_key` is the same nulled-on-resolve unique key as
  `ModerationCase::open_key`: re-detecting an open condition refreshes that alert instead of
  duplicating it, and resolving frees the key so the condition can legitimately alert again.
  Severity ratchets up only, so a late-arriving moderator still sees the peak.
- Stale **Info** alerts expire on their own (`stale_info` in `config/moderation.php`): the
  tripwire raises one per ranked item and a post that leaves the top-K is simply never
  re-detected, so nothing would ever close them. `ModerationAlertState::Expired` is
  deliberately distinct from `Resolved` — the queue must never imply a human reviewed
  something nobody reviewed. Expiry infers "condition cleared" from the *absence* of a
  re-detection, so it is skipped whenever a detector failed or a single detector was run
  with `--detector`; either would look identical to a condition clearing.
- `TrendingTripwireDetector` is the only *proactive* rule — it fires on reach (top-K), before
  anyone has reported anything, which is why a clean ranked item is only `Info`.
  `config('moderation.alerts.trending_tripwire.only_when_reported')` turns that half off if
  it proves noisy, leaving the reactive behaviour.
- `Hashtag::$moderation_state` is the tag-level equivalent of `posts.recommendation_eligible`.
  `NotRecommended` removes discovery only (trending, explore, search); `Blocked` additionally
  404s the tag's own API routes — except `DELETE /hashtags/{name}/follow`, kept reachable so
  a user who followed a tag before it was blocked isn't stuck with it. Neither state touches
  the posts carrying the tag. The state is not mass-assignable; it changes only through
  `HashtagModerationService`.
- Suppression is enforced in three places for two different reasons: `HashtagService::trending()`
  and `SearchService::hashtags()` filter in the query (required for the `database`/`collection`
  Scout drivers, which have no index), while `Hashtag::shouldBeSearchable()` keeps moderated
  tags out of a real index if the driver is ever switched. Both are needed.

### OCR: provenance, evaluation, and why the dashboard redacts

- Two paths produce `post_media.ocr_text`. The **server** path (`ExtractPostMediaText` →
  Tesseract) and the **device** path (the app uploads to R2 with its own OCR claim;
  `OcrTrustSampler` decides whether the server re-reads the image at all). `ocr_source` says
  which — it exists on `post_media` because `media_analysis_items`, where it used to live
  alone, are deleted at publish.
- **`ocr_status = ready` does not mean OCR ran.** Seeded rows and trusted device claims are
  `ready` with no engine behind them, which silently corrupts any rate computed over them.
  `PROCESSING_SKIPPED` marks "never ran" on `post_media`; `OcrInsightsService` divides
  outcome rates by *runs*, not rows. The staged-path item status was deliberately **not**
  changed to match — `MediaAnalysis::syncStatusIfReady()` requires every item `ready` before
  a publish is allowed, and `MediaAnalysisResource` exposes the value to the Android client,
  so "correcting" it there breaks publishing.
- `ocr_verifications` is the durable half of the trust loop. The comparison
  `PublishMediaAnalysis` performs used to die with the `MediaAnalysis` a few lines later, so
  no trend was measurable. It stores **hashes, counts and scores — never text**: the rows are
  permanent and OCR text is user content full of credentials.
- Two different numbers, deliberately. **Agreement** is whether both readings produced the
  same `CategoryMatcher` category — the right test for "is this device lying", which is what
  the trust loop acts on, but two unrelated texts that both map to "Social" score as a match.
  **Similarity** (`OcrTextSimilarity`, token Jaccard) is how much the text actually overlapped.
  High agreement over low similarity means the trust test is too coarse; that is the signal
  the dashboard exists to surface.
- Only device-sourced media gets a verification row. The server path has no claim to compare
  against, so a row would say nothing and would dilute every agreement rate.
- The dashboard **redacts OCR text by default**; revealing one row needs `manageModeration`
  plus a written reason and writes an `ocr.text_revealed` audit record. Rendering it in bulk
  would turn the admin panel into a searchable index of users' private screenshots — the same
  reasoning behind `MediaAnalysisItem::suggestedAltText()` returning null on a safety warning.
  The table's search deliberately never touches `ocr_text`.
- `SOCIAL_OCR_LANGUAGE` is passed to `tesseract -l` and **every language named needs its
  traineddata installed on the host** (`tesseract --list-langs`). A missing pack does not
  error — extraction returns empty and the row records a successful run that found no text.
  The app ships an Arabic UI, so the default is `eng+ara` and the host needs
  `tesseract-ocr-ara`. Changing this changes the extractor's version string, so existing
  media are treated as stale rather than keeping their old result.

### Views

- `resources/views/pages/**` — starter-kit-provided auth/settings pages, referenced via the
  `pages::` view namespace (see `FortifyServiceProvider::configureViews()`).
- `resources/views/{dashboard,devices,events}.blade.php` + `resources/views/livewire/*` — the
  telemetry-specific pages and their Livewire table partials.
- `resources/views/flux/**` — local overrides/extensions of Flux UI components.

### Operational monitoring (Horizon, Pulse, Telescope)

- `QUEUE_CONNECTION` is `redis` (not `database`) — required for Horizon to manage queues.
  Three supervisors in `config/horizon.php` (`supervisor-default`, `supervisor-security`,
  `supervisor-media`) map 1:1 to the queues jobs already declare via `onQueue()`; per-job
  `$tries`/`timeout`/`backoff()` (e.g. `DeliverSecurityOutboxMessage`) take precedence over
  the supervisor-level fallbacks. `composer dev` now boots `php artisan horizon` instead of
  `queue:listen`, so local dev requires Redis running (already true for the trending feed).
- Both `/horizon` and `/telescope` are gated by admin-only Gates (`viewHorizon`,
  `viewTelescope` in their respective `app/Providers/*ServiceProvider::gate()`) checking
  `User::$is_admin` — same boundary as `viewTelemetry`, since both dashboards expose
  data (job payloads; full request/response bodies and query bindings) at least as
  sensitive as the telemetry they're meant to help debug.
- Both packages also depend on `laravel/sentinel`, which wraps their routes in
  `SentinelMiddleware` for IP/tunnel-based checks. Its default `Laravel` driver only
  restricts access when `APP_ENV=local` (guarding against accidentally exposing a local
  dev server via ngrok/expose) and authorizes unconditionally in every other environment
  — it is **not** the production authorization boundary. That boundary is the Gate-based
  `Horizon::auth()`/`Telescope::auth()` checks wired into each package's own controller
  middleware, independent of Sentinel and of `config('horizon.middleware')` /
  `config('telescope.middleware')`.
- **Pulse is the production monitoring surface; Telescope is local-only.** Telescope is a
  `require-dev` package, listed under `extra.laravel.dont-discover`, and registered *only* by
  `AppServiceProvider::registerTelescope()` — which returns early outside `local`/`testing` and
  is additionally `class_exists()`-guarded so a production `composer install --no-dev` is a
  no-op rather than a fatal. It is deliberately absent from `bootstrap/providers.php`.
  `tests/Feature/MonitoringAccessTest.php` locks both halves of this boundary; don't "fix" a
  failure there by re-adding the provider.
- Because Telescope does not exist in production, `telescope:prune` is wrapped in a
  `class_exists(\Laravel\Telescope\Telescope::class)` check in `routes/console.php` —
  scheduling it unconditionally would fail the production scheduler run.
- Telescope's `register()` filter still records every request/exception (not just failures)
  where it *is* loaded, so `telescope_entries` grows continuously in local dev; the guarded
  daily `telescope:prune --hours=48` bounds it. `config/telescope.php`'s `ignore_paths`
  excludes `horizon*`/`telescope*` so each dashboard's polling doesn't flood the other's.
- `/pulse` is gated by `viewPulse` (`PulseServiceProvider`), the same admin-only boundary as
  `viewHorizon`/`viewTelescope`/`viewTelemetry` — Pulse exposes slow-query SQL, exception
  messages and locations, and per-user activity. The gate takes `?User` because Pulse
  evaluates it for guests too.
- Pulse uses `PULSE_INGEST_DRIVER=redis` in production so request handling stays off the write
  path: requests push to a Redis stream and a separate `pulse:work` daemon drains it into
  PostgreSQL. `pulse:check` is a second daemon for per-server stats. Locally the default
  `storage` ingest writes straight to the DB, so neither daemon is needed. Pulse trims itself
  on ingest (`PULSE_STORAGE_KEEP`) and needs no scheduled prune — unlike Telescope, and
  separate again from `TelemetryEvent`'s `TELEMETRY_RETENTION_DAYS` (different data, different
  lifecycle).
- `config/pulse.php` and the published Pulse migration are analysed by PHPStan like any other
  app code: the stock stubs fail level 7 (an `env()` that can return `bool` fed to `explode`,
  and `match` expressions with no `default` arm), so both carry local fixes. Re-publishing
  Pulse's assets will reintroduce those errors.

### Notable non-obvious packages

- `livewire/flux` — the paid Flux UI Pro component kit (requires the composer.fluxui.dev credentials
  above).
- `livewire/blaze` — folds Blade components into parent templates at build time for perf; not
  app-specific logic.
- `laravel/pao` — formats PHPUnit/Pest/PHPStan output for agent consumption; irrelevant to runtime
  behavior.
- `laravel/chisel` — dev-only toolkit for scripted dead-code/dependency removal.

### Config quirks worth knowing

- `AppServiceProvider::boot()` forces `URL::forceScheme('https')` unconditionally (even locally) and
  prohibits destructive DB commands in production.
- `Password::defaults()` only enforces the strict policy (12 chars, mixed case, symbols,
  uncompromised) in production; local/testing has no extra password rules beyond Fortify's base.
- No CORS config exists, intentionally — the only `/api/v1/*` consumer is a native Android app,
  and CORS is a browser-only enforcement mechanism. If a web client (admin panel, marketing site,
  etc.) is ever added, that's the trigger to add `config/cors.php` — don't add it speculatively.
- `routes/console.php` schedules `posts:prune-deleted` (daily) to permanently purge soft-deleted
  `Post`s + their media files past `config('social.post_retention_days')` (30 by default). This app
  previously had zero scheduled tasks, so any deploy environment needs a
  `* * * * * php artisan schedule:run` cron entry for this to actually fire — same operational
  category as `composer dev`'s queue worker, but a separate process.
