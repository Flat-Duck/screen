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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
