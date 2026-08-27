# Production Readiness Checklist

**First analysis:** 2026-08-26 · **Last updated:** 2026-08-27 (backend deploy-readiness pass)
**Scope:** `screenshut-telemetry` (Laravel backend) + `screenshot-detector` (Android app)

## Verdict

| Component | Status |
|---|---|
| **Backend** | **Ready to deploy — deploy path rehearsed.** CI fully green including the first-ever PostgreSQL run. A production install (`--no-dev`, cached, real Postgres) was dry-run end to end and verified. Runbook and process units shipped. Everything left needs server or staging access. |
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

## Backend — done since first analysis

- [x] **B3 — Pushed, CI fully green.** Both matrix legs pass, and the **PostgreSQL job succeeded for
  the first time in the project's history**. It immediately earned its keep: see the two CI fixes below.

- [x] **CI was non-deterministic across the matrix.** PHPStan inherited each runner's PHP, so the
  8.5 leg failed on `DifferenceHashService.php:20` while 8.4 passed — on 8.5 `imagecopyresampled()`
  declares a `true` return type rather than `bool`, making the defensive branch provably dead.
  Pre-existing code, newly visible. Pinned `phpVersion: 80400` in `phpstan.neon` (composer.json's
  floor) so analysis is deterministic everywhere; the runtime guard stays, since it is reachable on 8.4.

- [x] **`fail-fast` was hiding the PostgreSQL result.** The 8.5 failure *cancelled* the 8.4 leg
  mid-run — the leg carrying the Postgres job. Added `fail-fast: false`.

- [x] **Real test-isolation bug found by the Postgres job.** Three tests failed with "one extra row
  than expected". `PostgresSocialConcurrencyTest` forks child processes that write on their own
  connections, so it correctly uses `DatabaseTruncation` — but that trait truncates only *before*
  each test, and returns early after `migrate:fresh` on its first run. Every later class uses
  `RefreshDatabase`, which finds `RefreshDatabaseState::$migrated` already true, skips its own
  `migrate:fresh`, and merely opens a transaction — so the forked children's committed rows survived
  the whole run and broke downstream `assertDatabaseCount` assertions. Long-standing, invisible until
  the job first ran. Isolated by re-running the same list minus that class (88/88 pass, DB left empty),
  fixed with a truncating `tearDown()`. Now **90/90, stable over three consecutive runs.**

- [x] **Production install dry run.** Rehearsed the whole deploy in an isolated git worktree against
  real PostgreSQL, to de-risk the `require-dev` Telescope move — exactly the change that breaks a
  production boot. `composer install --no-dev` → `migrate --force` (creates `pulse_*`) →
  `config/route/view/event:cache` → boot. All clean. Verified in that production-mode instance:

  | Check | Result |
  |---|---|
  | `artisan about` | `production`, Debug **OFF**, all four caches CACHED, redis + pgsql |
  | Telescope routes / commands | **0 / 0** — absent, as designed |
  | Pulse route + `pulse:work`/`pulse:check` | present |
  | `schedule:list` | 15 tasks, `telescope:prune` correctly absent |
  | `/up` | 200 |
  | `/up/deep` (no secret / wrong secret) | **404 / 404** |
  | `/up/deep` (correct secret) | 200 — `database ok, queue ok (backlog 0), storage ok` |
  | `/pulse`, `/horizon` logged out | **403** — never 200 |
  | `/telescope` | **404** |

- [x] **Deployment runbook + process units.** `docs/runbooks/deploy.md` (first install, per-deploy,
  post-deploy verification, rollback, and a table of failure modes), plus ready-to-install
  `deploy/supervisor/*.conf` and `deploy/cron/screenshut-scheduler`. Every command is pinned to
  `/usr/bin/php8.4`. Commands referenced in it were verified to exist — which caught a bad
  `--render="errors::503"` flag (no `resources/views/errors` in this app) before it reached the runbook.

## Still open — backend (all require server or staging access)

- [ ] **Deploy.** Follow `docs/runbooks/deploy.md`. The dry run above rehearsed every step, so this
      should hold no surprises — but `migrate --force` for the `pulse_*` tables is part of it.
- [ ] **B8 — Install the four processes** from `deploy/`. Scheduler cron, `horizon`, `pulse:work`,
      `pulse:check`. Prior ops notes pin `/usr/bin/php8.3`, which now fatals on Composer's platform
      check — the shipped units pin 8.4.
- [ ] **Wire the alerting.** The highest-value remaining item. Pulse is a dashboard, not a pager;
      `/up/deep` is verified working and correctly returns 404 without the secret, so it is ready
      for a monitor — but nothing is watching it yet, and nobody gets woken up.
- [ ] **B6 — k6 baseline (harness now validated; capacity number still needs staging).**
      Installed k6 2.2.0 and ran the suite end to end against a disposable local instance
      (scratch PostgreSQL, seeded account). The harness works — scenarios execute, metrics
      collect, thresholds evaluate. **Two defects in the harness were found and documented in
      `load/README.md`:**
      1. *The analytics scenario could never pass.* `POST /v1/analytics/content-events` requires
         an active `DeviceSession` bound to the exact token presented, but `load/README.md` said
         any "user Sanctum token". A `createToken()` token returns 401 every time; a token from
         the device-backed login flow returns 200. Verified both ways. Fixing the token alone cut
         the failure rate from **42.5% to 14%**.
      2. *One token cannot generate load.* Every limiter is per-user, and `readJourneys` calls
         `search/posts` each iteration against a **20/min** budget (confirmed via
         `X-RateLimit-Limit: 20`). A single `USER_TOKEN` measures the rate limiter, not the app —
         a real run needs a pool of accounts sharded across VUs.
      Timings from that run are **not** a capacity baseline: `artisan serve` on a laptop was the
      bottleneck. A real baseline still needs staging, and `docs/future_12_release_readiness.md`
      forbids aiming this at production without written change authority.
- [ ] **B7 — Backup/restore drill.** Needs the managed snapshot facility, R2 versioning, and an
      isolated environment — not reproducible locally. The runbook's own verification commands
      (`about`, `migrate:status`, `api:export-contract --check`) were confirmed working in the
      production-mode install, so the drill will not stall on a broken step.

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
