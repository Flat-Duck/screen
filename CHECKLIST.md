# Production Readiness Checklist

**Analysis date:** 2026-08-26
**Scope:** `screenshut-telemetry` (Laravel backend) + `screenshot-detector` (Android app)

## Verdict

| Component | Status |
|---|---|
| **Backend** | **Near-ready.** All quality gates pass locally. Blocked on CI correctness, error alerting, and a Postgres verification gap. |
| **Android** | **Not ready.** Blocked on a committed signing key, a red CI pipeline, and the entire Play Store compliance track. |

> **Verification caveat:** the backend was fully executed and verified on this machine. The Android
> app **was not built or tested** — there is no Java runtime installed here (`/usr/libexec/java_home`
> finds nothing, no Android Studio JBR). Every Android finding below is **static review only**.
> Re-run `./gradlew lintDebug testDebugUnitTest verifyRoborazziDebug` on a machine with JDK 21
> before trusting any Android "pass" claim.

---

## What was actually verified (backend)

Run with PHP 8.4.24 (`/Applications/ServBay/package/php/8.4/8.4.24/bin/php`) — the repo's
default `php` on this machine is 8.3.30 and **cannot run the app at all** (see B1).

| Gate | Command | Result |
|---|---|---|
| Test suite | `php artisan test` | **621 passed**, 2 skipped, 0 failed (623 total, 5358 assertions, 12.5s) |
| Static analysis | `phpstan analyse` (larastan, level 7) | **0 errors** |
| Code style | `pint --parallel --test` | **passed** |
| API contract drift | `artisan api:export-contract --check` | **contract is current** |
| Debug leftovers | grep `dd(`/`dump(`/`var_dump(` in `app/` | **none** |
| Open TODOs | grep in `app/` | **1** (deliberate, `CommitUpload.php:42`) |

Backend infrastructure already in place and confirmed present:

- [x] 20+ named rate limiters covering auth, reads, writes, search, uploads, messaging, reports
- [x] 16 scheduled tasks, all `onOneServer()->withoutOverlapping()`
- [x] 5 incident runbooks (`docs/runbooks/`)
- [x] k6 load-test suite (`load/k6/mobile-api.js`) with p95/error thresholds
- [x] OpenAPI contract committed and drift-checked in `composer test`
- [x] Admin-only Gates on telemetry dashboard, Horizon, and Telescope
- [x] `/up/deep` health endpoint gated behind `HEALTH_CHECK_SECRET`
- [x] Data retention: telemetry, analytics, Telescope (48h), soft-deleted posts/users

---

## Blockers — must fix before production

### Backend

- [ ] **B1 — CI's PHP 8.3 leg is broken, and it is the only leg that tests PostgreSQL.** `HIGH`
  `composer.json` declares `"php": "^8.3"` and `.github/workflows/tests.yml` runs a
  `['8.3','8.4','8.5']` matrix — but `composer.lock` contains `symfony/filesystem`, which requires
  `php >=8.4.1`. On PHP 8.3 the platform check fatals before the app boots (reproduced locally:
  `Composer detected issues in your platform: ... require a PHP version ">= 8.4.1"`). There is no
  `config.platform` override to mask it.
  **Consequence:** the 8.3 job fails at `composer install`. The PostgreSQL integration tests
  (`PostgresSocialConcurrencyTest`, `UnifiedDeviceLifecycleTest`, `TelemetryApiTest`,
  `WorkflowDurabilityTest`, and 6 auth suites) are gated on `if: matrix.php-version == '8.3'` —
  so **the production database engine has never been exercised in CI.** The default suite is SQLite.
  **Fix:** raise `composer.json` to `"php": "^8.4"`, drop `8.3` from the matrix, and move the
  Postgres job to the `8.4` leg (or a standalone job).

- [ ] **B2 — No external error tracking or alerting.** `HIGH`
  No Sentry/Bugsnag/Flare in `composer.json` or `config/`. Telescope is a self-hosted debugging
  UI pruned every 48h, not an alerting system — nobody gets paged on a 500, a queue stall, or a
  failed job. `/up/deep` exists but no uptime monitor is wired to it (`HEALTH_CHECK_SECRET` is
  unset locally).
  **Fix:** add an error tracker, wire `/up/deep` to an uptime monitor, set alert routing.

- [ ] **B3 — 1 unpushed commit on `main`.** `MEDIUM`
  The R2-backed uploads + OCR trust pipeline commit has never been through CI. Push and get a
  green run (after B1) before it reaches production.

- [ ] **B4 — Deployment docs contradict the runtime.** `MEDIUM`
  `README.md:166-172` documents raw `queue:work --queue=security|media|default` workers, but
  `QUEUE_CONNECTION=redis` and `config/horizon.php` defines three supervisors — Horizon owns the
  queues. Following the README on a production box gives you double-consuming workers alongside
  Horizon. Reconcile the README with the Horizon setup.

- [ ] **B5 — Production env hardening not codified.** `MEDIUM`
  `.env.example` ships `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`, and `composer setup`
  copies it and runs `migrate --force`. Nothing in the repo asserts the production values.
  **Fix:** add a documented production env template + a deploy-time assertion that `APP_DEBUG=false`,
  `APP_ENV=production`, `SESSION_ENCRYPT=true`, and `TELESCOPE_ENABLED` is a deliberate choice.

### Android

- [ ] **A1 — A signing keystore is committed to git.** `CRITICAL`
  `upload-keystore.jks` is tracked (added in commit `0ed52db`). `.gitignore` lists `*.jks`, but
  gitignore does not untrack files already committed. The key is in the repo **and in history**,
  readable by anyone with clone access, past or present.
  **Fix:** treat the key as compromised. Confirm whether it was ever used to sign a published
  artifact; if so, rotate via Play App Signing key upgrade. Purge from history
  (`git filter-repo`), force-push, and rotate any credential derived from it.

- [ ] **A2 — The active signing key exists on exactly one machine, with no backup.** `CRITICAL`
  `app/build.gradle.kts` defaults `storeFile` to `${rootDir}/my-upload-key.jks`, which is correctly
  git-ignored — and therefore exists only on this laptop. No documented backup, no escrow.
  If it is lost and Play App Signing is not enrolled, **the app can never be updated.**
  **Fix:** enrol in Play App Signing, back the upload key up to a password manager / secure escrow,
  and document recovery in a runbook.

- [ ] **A3 — Android CI is red: 191 untranslated strings.** `HIGH`
  `values/strings.xml` has 730 strings; `values-ar/strings.xml` has 539 — a **191-string gap**
  (only 1 marked `translatable="false"`). `android-ci.yml` runs `./gradlew lintDebug ...`, and
  `MissingTranslation` is an error-severity lint check with no `lint { }` block relaxing it, so
  the CI job fails. This is also a user-facing defect: Arabic is a declared locale
  (`localeConfig`, `supportsRtl`), so those users see English mid-app.
  **Fix:** translate the 191 strings, or make an explicit, documented decision to demote
  `MissingTranslation` — not a silent suppression.

- [ ] **A4 — 5 unpushed commits, none build-verified.** `HIGH`
  Includes the entire R2 direct-upload protocol (repository, API definitions, SHA-256 utilities).
  `docs/PRODUCTION_READINESS.md` already records that a prior 7-phase effort shipped code-complete
  but never build-verified, and that this produced a real crash (`MediaAnalysisApi` wildcard bug)
  found only on-device. Same risk profile applies here.
  **Fix:** build and test locally on a JDK-21 machine, then push and get CI green.

- [ ] **A5 — Release builds trust user-installed CAs.** `MEDIUM-HIGH`
  `res/xml/network_security_config.xml` sets `<certificates src="user" />` inside `base-config`,
  which applies to release builds. Any device with a user CA installed (a corporate MDM profile,
  or an attacker-provisioned proxy) can transparently intercept every API call — including the
  device and user bearer tokens.
  **Fix:** drop `src="user"` from the release config; the debug override at
  `app/src/debug/res/xml/network_security_config.xml` already handles local proxying.

- [ ] **A6 — Play Store compliance track is entirely unstarted.** `HARD BLOCKER`
  Per `docs/PRODUCTION_READINESS.md` Phase 2, none of the following exist:
  - [ ] Privacy Policy + Terms of Service (hosted, linked in-app and in the listing)
  - [ ] **Accessibility API declaration + demo video** — the app ships
        `ScreenshotAccessibilityService`; this is one of the most common rejection causes
  - [ ] Data Safety form (images, DMs, account info, telemetry)
  - [ ] Store listing assets (screenshots, feature graphic, descriptions)
  - [ ] Signed-release dry run (`KEYSTORE_PATH`/`STORE_PASSWORD`/`KEY_PASSWORD` end-to-end + real-device install)

  The manifest additionally requests `SYSTEM_ALERT_WINDOW`,
  `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`, `FOREGROUND_SERVICE_SPECIAL_USE`, and
  `FOREGROUND_SERVICE_MEDIA_PROJECTION` — each policy-sensitive and each needing justification.

---

## Should fix before launch (not blockers)

- [ ] **A7** — Android test coverage audit. 187 unit tests against a very large social surface;
      `docs/PRODUCTION_READINESS.md` flags auth, post-safety-check, and DMs as thin.
- [ ] **A8** — Manual regression pass using `docs/APP_SCREENS.md` as the checklist.
- [ ] **A9** — Real-device background-reliability pass (Xiaomi/Samsung OEM battery killers —
      code exists, unverified on hardware).
- [ ] **A10** — `versionCode` is still `1` with `versionName "1.0.1"`. Automate the bump; Play
      rejects duplicate version codes.
- [ ] **A11** — Collection drag-reorder UI missing (server-side `position` support exists).
- [ ] **B6** — Run the k6 load suite against staging and record a baseline. It exists but there is
      no evidence it has been run.
- [ ] **B7** — Execute the `backup_restore.md` runbook as a real drill; an untested restore is not
      a backup.
- [ ] **B8** — Verify the `* * * * * schedule:run` cron and Horizon supervisors are live on the
      production host, pinned to PHP 8.4+ (note: prior ops notes pin `/usr/bin/php8.3`, which
      **will now fatal** — see B1).

## Accepted / deliberate — no action

- Facebook sign-in is a fail-closed client stub (backend endpoint exists). Documented decision.
- No `config/cors.php` — the only `/api/v1` consumer is a native Android app; CORS is
  browser-only enforcement. Documented in `CLAUDE.md`.
- Telescope records every request in every environment, by design, pruned at 48h.
- On-device OCR is Latin-script only (no ML Kit Arabic model). Known gap, not a bug.
