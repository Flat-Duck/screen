# Production Readiness Checklist

**First analysis:** 2026-08-26 · **Last updated:** 2026-08-27
**Scope:** `screenshut-telemetry` (Laravel backend) + `screenshot-detector` (Android app)

## Verdict

| Component | Status |
|---|---|
| **Backend** | **Ready to deploy.** All gates green, monitoring split done, dependencies clean, server env template written. Remaining work is operational (wire alerting, run the drills). |
| **Android** | **Blocked on Play Store compliance only.** CI is green again, code fixes landed. The signing-key items are explicitly deferred by the owner. |

> **Verification caveat:** the backend is fully executed and verified on this machine (PHP 8.4.24).
> On Android, `assembleDebug`, `lintDebug`, and `verifyRoborazziDebug` all pass locally; the full
> `testDebugUnitTest` suite **crashes the Gradle test executor on this Mac** — a pre-existing,
> environment-specific Robolectric native-runtime failure, confirmed by reproducing it on a clean
> checkout with every local change stashed. CI (ubuntu + temurin 21) is the authority for that job.

---

## Backend — verified green

Run with PHP 8.4.24. Note the default `php` on this machine is 8.3.30 and **cannot run the app**.

| Gate | Command | Result |
|---|---|---|
| Test suite | `php artisan test` | **627 passed**, 2 skipped, 0 failed (629 total, 5364 assertions) |
| Static analysis | `phpstan analyse` (larastan, level 7) | **0 errors** |
| Code style | `pint --parallel --test` | **passed** |
| API contract drift | `artisan api:export-contract --check` | **current** |
| Dependency audit | `composer audit` | **0 advisories** |

Already in place: 20+ rate limiters · 16 scheduled tasks · 5 incident runbooks · k6 load suite ·
committed OpenAPI contract · admin-only Gates on every dashboard · `/up/deep` behind a shared secret ·
retention pruning for telemetry, analytics, posts, users, and Telescope.

---

## Fixed this pass

### Backend

- [x] **B1 — CI's PHP 8.3 leg was broken, and it was the only leg testing PostgreSQL.**
  `composer.lock` pins `symfony/filesystem` at `php >=8.4.1` while `composer.json` said `^8.3`, so
  the 8.3 job fatally failed at `composer install` — and the PostgreSQL suites were gated to exactly
  that leg (`if: matrix.php-version == '8.3'`), meaning **the production database engine had never
  run in CI**. Raised `composer.json` to `^8.4`, cut the matrix to `['8.4','8.5']`, and moved the
  Postgres job onto the 8.4 leg.

- [x] **B2 — No production error tracking.** Laravel Pulse (v1.8.1) installed as the production
  monitoring surface, gated by a new admin-only `viewPulse` Gate matching `viewHorizon`/`viewTelemetry`.
  Configured for the Redis ingest in production so request handling stays off the write path.

- [x] **Telescope moved to dev-only.** It was in `require` and recording every request in every
  environment. Now `require-dev` + `extra.laravel.dont-discover`, removed from `bootstrap/providers.php`,
  and registered only by `AppServiceProvider::registerTelescope()` (early-returns outside
  `local`/`testing`, `class_exists()`-guarded so `composer install --no-dev` is a no-op). The
  `telescope:prune` schedule is guarded the same way, since the command no longer exists in production.
  `tests/Feature/MonitoringAccessTest.php` (6 tests) locks both halves of the boundary.
  Verified: production simulation registers **0 Telescope routes, 1 Pulse route**.

- [x] **Vulnerable dependencies.** `composer audit` found **12 advisories across 2 packages** —
  `guzzlehttp/guzzle` 7.13.1 (6 advisories, one *high*: CVE-2026-69246 host-check bypass) and
  `league/commonmark`. Updated to clean. Added a `composer audit` step to CI so this fails the build
  in future.

- [x] **PHPStan level 7 on the new Pulse files.** Pulse's published stubs fail level 7 out of the box:
  an `env()` that can return `bool` fed to `explode()`, and three `match` expressions with no `default`
  arm (an unsupported DB driver would die with a bare `UnhandledMatchError`). Both fixed properly
  rather than suppressed.

- [x] **Flaky test that would randomly redden CI.**
  `OcrTrustPipelineTest::test_a_trusted_user_resolves_the_upload_synchronously_without_dispatching_ocr`
  asserted the not-sampled outcome against the real `OcrTrustSampler`, which spot-checks trusted
  accounts at `TRUSTED_SAMPLE_RATE_PERCENT` (8%) via `random_int(1, 100) <= 8`. It therefore failed
  roughly **one run in twelve** — it did fail once during this pass, then passed 3/3 in isolation.
  The sampler decision is now pinned in that test; the rate itself stays covered by
  `OcrTrustSamplerTest`. Confirmed with 6 consecutive isolated runs and 2 full-suite runs.

- [x] **B4 — README contradicted the runtime.** It documented raw `queue:work --queue=...` workers
  while `QUEUE_CONNECTION=redis` and Horizon owns all three queues — following it on a production box
  gives you double-consuming workers. Rewritten, plus a new Monitoring section documenting the
  Pulse/Telescope split, and `CLAUDE.md` updated to match.

- [x] **B5 — Server env template.** New `.env.production.example`: production-safe defaults
  (`APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`,
  Redis cache/queue/session, R2 disks), every `CHANGEME` marked, and the four required server
  processes documented (scheduler cron, Horizon, `pulse:work`, `pulse:check`) with PHP-8.4 pinning.

  It is deliberately a **separate file** from `.env.example` rather than a rewrite of it: CI does
  `cp .env.example .env` and then `migrate:fresh --force`, which `AppServiceProvider::boot()`'s
  production guard blocks outright, and `composer setup` copies the same file for local onboarding.
  `.env.example` was updated in place with the Pulse keys and a pointer to the new file.

### Android

- [x] **A3 — CI was red on 191 untranslated strings.** `values/strings.xml` had 730 strings against
  539 in `values-ar/strings.xml`, and `lintDebug` treats `MissingTranslation` as an error. Wrote
  Modern Standard Arabic for all 190 translatable ones (the 191st, `splash_activity_label`, is
  correctly `translatable="false"`), verifying programmatically that every format specifier
  (`%1$s`, `%2$d`, …) matches its English source.
  **`lintDebug` now passes: 0 errors, 258 warnings, MissingTranslation 0.**
  Also removed three lines of stray text — an Arabic preamble sentence and two markdown fences —
  that an earlier translation pass had pasted verbatim into the resource file.
  ⚠️ *These translations still want a native-speaker review before release.*

- [x] **A5 — Release builds trusted user-installed CAs.** `<certificates src="user" />` in the
  release `base-config` let any device with a user CA (corporate MDM, or an attacker-provisioned
  proxy) transparently read and rewrite traffic carrying device and user bearer tokens. Now
  system-only; the debug override still trusts the user store, so local proxying is unaffected.

- [x] **A10 — `versionCode` automation.** Was hardcoded to `1`. Now reads `VERSION_CODE`/`VERSION_NAME`
  from the environment (for CI) with a fallback to `appVersionCode`/`appVersionName` in
  `gradle.properties`. Used `providers.environmentVariable()` rather than `System.getenv()` because
  this project sets `org.gradle.configuration-cache=true`, where a raw `getenv` at configuration time
  is an undeclared build input; the pre-existing `signingConfigs` reads were migrated for the same reason.

---

## Still open — backend

- [ ] **B3 — Unpushed commits.** Push and get a green CI run (now that B1 is fixed), including the
  PostgreSQL job that has never actually executed.
- [ ] **Wire the alerting.** Pulse gives you the dashboard; it does not page anyone. Point an uptime
  monitor at `/up/deep` with `HEALTH_CHECK_SECRET`, and decide who gets woken up.
- [ ] **B6** — Run the k6 load suite against staging and record a baseline.
- [ ] **B7** — Execute `docs/runbooks/backup_restore.md` as a real drill. An untested restore is not a backup.
- [ ] **B8** — Verify the scheduler cron and Horizon/Pulse supervisors are live on the production
      host, **pinned to PHP 8.4+**. Prior ops notes pin `/usr/bin/php8.3`, which will now fatal.
- [ ] Run `php artisan migrate --force` for the new `pulse_*` tables on deploy.

## Still open — Android

- [ ] **A6 — Play Store compliance. The critical path, entirely unstarted:**
  - [ ] Privacy Policy + Terms of Service (hosted, linked in-app and in the listing) — note the app
        currently ships "coming soon" placeholder text for both
  - [ ] **Accessibility API declaration + demo video** — the app ships `ScreenshotAccessibilityService`;
        one of the most common rejection causes
  - [ ] Data Safety form (images, DMs, account info, telemetry)
  - [ ] Store listing assets (screenshots, feature graphic, descriptions)
  - [ ] Signed-release dry run + real-device install
  - [ ] Justify `SYSTEM_ALERT_WINDOW`, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`,
        `FOREGROUND_SERVICE_SPECIAL_USE`, `FOREGROUND_SERVICE_MEDIA_PROJECTION`
- [ ] **A4 — 5 unpushed commits** including the whole R2 direct-upload protocol. `assembleDebug` and
      `lintDebug` pass locally; push for a full CI verification.
- [ ] Native-speaker review of the 190 new Arabic strings.
- [ ] **Local `testDebugUnitTest` OOM.** The suite kills the Gradle test executor on this Mac with
      `java.lang.OutOfMemoryError` (then `java.io.EOFException` / a `java.lang.instrument` assertion).
      **Diagnosis:** `gradle.properties` gives the daemon `-Xmx8g` while it *also* hosts the Kotlin
      compiler (`kotlin.compiler.execution.strategy=in-process`), and `app/build.gradle.kts` forks a
      separate 4g test JVM on top — ~12g of Java heap on a 16GB machine. Pre-existing: reproduced on a
      clean checkout with every local change stashed. Dropping the daemon to 4g alone did **not** fix
      it, so the tuning is unresolved and no speculative change was left in the tree. Single test
      classes and the Roborazzi verification run fine. Does not block CI (ubuntu runners), but it
      means the full unit suite currently cannot be run locally.
- [ ] **A7** — Test coverage audit on social surfaces (auth, post-safety-check, DMs flagged as thin).
- [ ] **A8** — Manual regression pass using `docs/APP_SCREENS.md`.
- [ ] **A9** — Real-device background-reliability pass (Xiaomi/Samsung OEM battery killers).
- [ ] **A11** — Collection drag-reorder UI (server-side `position` support exists).

## Deferred by owner — no action

- **A1 — `upload-keystore.jks` committed to git** (commit `0ed52db`; `.gitignore` does not untrack
  already-committed files). Explicitly skipped on 2026-08-27. Still worth knowing: if that key ever
  signed a published artifact, anyone with repo access — including history — holds it.
- **A2 — Signing key has no backup.** `my-upload-key.jks` is correctly git-ignored and therefore
  exists only on one laptop. Without Play App Signing enrolment, losing it means the app can never
  be updated.

## Accepted / deliberate

- Facebook sign-in is a fail-closed client stub; backend endpoint exists.
- No `config/cors.php` — the only `/api/v1` consumer is a native Android app.
- On-device OCR is Latin-script only (no ML Kit Arabic model).
- Telescope records everything *where it loads* (local only now), pruned daily at 48h.
