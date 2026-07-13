# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

A screenshot-sharing social platform whose Android installation, user sessions, FCM registration,
and crash telemetry share one device domain. `Device` is a nullable-current-user installation
identity, `DeviceSession` preserves login history, and telemetry is one `/api/v1` feature rather
than a separate application.

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

### Device-first authentication

`Device` (app/Models/Device.php) extends the same `Authenticatable` base as `User` and uses
`HasApiTokens` (Sanctum). This lets `auth:sanctum` resolve a `Device` as `$request->user()` on API
routes exactly like it would a `User`. Android stores two credentials: a restricted Device token
for enrollment/FCM/telemetry and a User token linked to a durable `DeviceSession` for social APIs.
Registration/login/social/2FA require the Device credential before issuing a User session.

### Unified device and telemetry flow

- `POST /api/v1/devices/enroll` creates an installation credential. Rotating an existing UUID
  requires that installation's current token and is transactional.
- `POST /api/v1/telemetry/events` requires the Device token. Device identity never comes from the
  payload. An optional per-event `session_id` is accepted only when it belongs to the authenticated
  device and the event occurred inside that session window.
- `PUT/DELETE /api/v1/devices/push-token` manages the authenticated installation's single FCM token.
- `devices.user_id` is the current account and becomes null on logout. Historical attribution is
  stored on `device_sessions` and snapshotted onto telemetry events.
- `TelemetryEvent` has three `kind`s (`KIND_EVENT`, `KIND_ERROR`, `KIND_FATAL_CRASH`); error-specific
  columns (`exception_class`, `stack_trace`, etc.) are nullable and populated only for the latter two
  — they're 1:1 with the row, not a separate table/relation. `scopeCrashes()` filters `kind != event`.
- Request validation in `StoreTelemetryEventsRequest` intentionally mirrors the Android client's
  `TelemetryBatchRequest`/`TelemetryEventPayload` field names exactly — keep them in sync if the
  client payload shape changes.
- Batches are capped at 50 events/512 KB; diagnostic context is bounded and redacted, stack traces
  are truncated to 4000 characters, and crashes receive a stable fingerprint.

### Dashboard (web, session auth)

`DashboardController`, `DeviceController`, `EventController` render Blade views; the interactive
searchable/sortable/paginated tables themselves are separate Livewire components
(`App\Livewire\DevicesTable`, `App\Livewire\EventsTable`) rendered inside those views — not the
controllers. Both use `#[Url]`-bound public properties for search/sort state and reset pagination
`updating*` hooks fire.

### Views

- `resources/views/pages/**` — starter-kit-provided auth/settings pages, referenced via the
  `pages::` view namespace (see `FortifyServiceProvider::configureViews()`).
- `resources/views/{dashboard,devices,events}.blade.php` + `resources/views/livewire/*` — the
  telemetry-specific pages and their Livewire table partials.
- `resources/views/flux/**` — local overrides/extensions of Flux UI components.

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
