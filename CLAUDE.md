# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel + Livewire (Flux) app that ingests telemetry (events, non-fatal errors, fatal crashes)
from an Android client and presents it in an admin dashboard. It's built on the official
`laravel/livewire-starter-kit` (Fortify auth, passkeys, 2FA, settings pages all come from that
starter kit's `pages::` view namespace under `resources/views/pages/`) with a telemetry-specific
domain layered on top: `Device`, `TelemetryEvent`, the `/api/telemetry/*` ingestion endpoints, and
the dashboard/devices/events Livewire tables.

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

### Telemetry ingestion flow (`routes/api.php` → `App\Http\Controllers\Api\TelemetryController`)

- `POST /api/telemetry/register` (throttled 20/min): creates a `Device` by `device_uuid` and mints
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
- `POST /api/telemetry/events` (`auth:sanctum`, throttled 120/min): batch-ingests events. The
  `device` block in the payload body is informational only (used to refresh app version / last seen)
  — identity comes solely from the Sanctum token, never from the body. Insertion is
  `firstOrCreate` keyed on `event_uuid`, making resends after an ambiguous network failure safe.
- `TelemetryEvent` has three `kind`s (`KIND_EVENT`, `KIND_ERROR`, `KIND_FATAL_CRASH`); error-specific
  columns (`exception_class`, `stack_trace`, etc.) are nullable and populated only for the latter two
  — they're 1:1 with the row, not a separate table/relation. `scopeCrashes()` filters `kind != event`.
- Request validation in `StoreTelemetryEventsRequest` intentionally mirrors the Android client's
  `TelemetryBatchRequest`/`TelemetryEventPayload` field names exactly — keep them in sync if the
  client payload shape changes.
- Stack traces are truncated to `TelemetryController::MAX_STACK_TRACE_LENGTH` (4000 chars) before
  storage.

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
