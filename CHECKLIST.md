# Production Readiness Checklist

**First analysis:** 2026-08-26 · **Last updated:** 2026-08-27 (backend deployed; focus shifts to Android)
**Scope:** `screenshut-telemetry` (Laravel backend) + `screenshot-detector` (Android app)

## Verdict

| Component | Status |
|---|---|
| **Backend** | **LIVE at https://akukas.ly.** Deployed, verified end to end, serving through Cloudflare. Only operational follow-ups remain. |
| **Android** | **The whole critical path now.** Three configuration mismatches would break production silently, and the entire Play Store compliance track is unstarted. |

---

# Part 1 — Backend: shipped ✅

Running on DigitalOcean `akukas-ubuntu-s-2vcpu-4gb-amd-fra1` (FRA1, 2 vCPU, 4 GB) with Neon
PostgreSQL, behind Cloudflare.

## Verified in production

| Layer | State |
|---|---|
| OS | Ubuntu 24.04, 2 GB swap, ufw, fail2ban, unattended-upgrades |
| PHP | **8.4.24** FPM (8 children) + opcache; `php8.3-fpm` disabled |
| Database | **Neon PostgreSQL 18.6**, `eu-central-1`, **8.5 ms/query**, all migrations applied |
| Redis | 512 MB cap, `noeviction`, AOF on |
| Queues | Horizon, 3 supervisors (3/1/2 workers, ~1.0 GB ceiling) |
| Monitoring | Pulse ingesting via the unpooled connection; Telescope absent |
| Scheduler | cron → 15 tasks |
| Web | nginx 1.24 + Cloudflare Origin Cert (to 2041), Full (strict) |
| Client IPs | real addresses via `CF-Connecting-IP`, origin firewalled to Cloudflare only |
| Media | **Cloudflare R2** — presign → PUT → verify → public fetch → delete, all verified |
| Push | **FCM authenticates** (live token exchange confirmed) |
| Mail | **Resend**, `no-reply@akukas.ly`, DKIM + SPF + MX present |

Security boundaries confirmed live: `/pulse` **403**, `/horizon` **403**, `/telescope` **404**,
`/up/deep` **404 without the secret** and 200 with it.

## Bugs found and fixed along the way

- **CI never tested PostgreSQL.** The 8.3 leg couldn't install dependencies, and the Postgres job
  was gated to exactly that leg. Fixed the matrix; the job has now run for the first time.
- **Two test-isolation bugs**, both surfaced by that first Postgres run: `DatabaseTruncation`
  leaking committed rows from forked processes, and truncation destroying migration-seeded
  `screenshot_categories`. Six tests were affected.
- **A flaky test** that would redden CI ~1 run in 12 (`random_int` in the OCR sampler).
- **Neon's pooler silently breaks Pulse** — its multi-statement transaction fails 100% through
  PgBouncer, 0% direct. Worker died, supervisor gave up, dashboard sat empty with no error.
  Fixed with a dedicated `pgsql_direct` connection.
- **A failed log write killed daemons.** `deploy` wasn't in `www-data`; a log permission error
  threw and took out `pulse:work`. Fixed with `0664` daily logs.
- **No trusted-proxy config.** Behind Cloudflare every visitor shared one throttle key — the
  10/min login limit would have applied to the entire internet. PHPStan then caught that my first
  fix used `env()` at bootstrap, which returns null once config is cached.
- **12 dependency advisories** including a high-severity Guzzle host-check bypass. `composer audit`
  now runs in CI.

## Backend — still open (operational, not code)

- [ ] **Rotate the Neon database password** — leaked into this session's transcript on 2026-08-27.
- [ ] **Neon IP allowlist** — currently empty; restrict to `46.101.134.187`.
- [ ] **`img.akukas.ly` Minimum TLS → 1.2.** Currently 1.3, and a TLS-1.2 client provably gets
      `http=000`. See A2 below — this is an *Android-breaking* setting.
- [ ] **DMARC record** — `_dmarc.akukas.ly TXT v=DMARC1; p=none; rua=...`. DKIM/SPF/MX are set;
      without DMARC, Gmail is likely to spam-folder password resets from a new `.ly` domain.
- [ ] **Wire alerting.** `/up/deep` is verified and ready for an uptime monitor, but nothing is
      watching it. Pulse is a dashboard, not a pager.
- [ ] **Backup/restore drill** — Neon PITR covers the database; it does **not** cover R2 media, and
      `docs/runbooks/backup_restore.md` treats the two as one recovery set. Note Neon history
      retention is **6 hours**; raise it.
- [ ] **k6 baseline** against staging. Harness validated; needs an account pool (per-user rate
      limits cap a single-token run) — see `load/README.md`.

---

# Part 2 — Android: the critical path 🎯

`screenshot-detector`, package `com.bulbulstream.akokas`, `versionCode=1` / `versionName=1.0.1`.

> **Verification caveat:** `assembleDebug`, `lintDebug` and `verifyRoborazziDebug` pass locally.
> The full `testDebugUnitTest` suite OOMs on the dev Mac (daemon `-Xmx8g` + in-process Kotlin
> compiler + a separate 4 GB test JVM on 16 GB). Pre-existing, reproduced on a clean checkout.
> **CI is the authority for that job.**

## P0 — Config mismatches that break production silently

These are new findings from the backend going live. Each fails with no error a user could report.

- [ ] **A1 — The app points at the wrong backend.**
      `.env` has `TELEMETRY_BASE_URL=https://screen.bulbulstream.ly`. Production is now
      **`https://akukas.ly`**. Every release build would talk to the old server.

- [ ] **A2 — Push cannot work: Firebase project mismatch.**
      | | project | number |
      |---|---|---|
      | Android `google-services.json` | `akokas-production` | 892534919192 |
      | Backend `FIREBASE_PROJECT_ID` | `akukas-app` | 887501771282 |

      FCM registration tokens are scoped to a project (the sender ID *is* the project number).
      The app registers with one project; the backend sends from another, so **FCM rejects every
      message**. Backend auth is confirmed working — this is purely a project mismatch.
      **Decision needed:** point the backend at `akokas-production` (swap the service-account key,
      no new APK) or regenerate `google-services.json` from `akukas-app` (new build required).
      `akokas-production` looks like the established project — it owns the storage bucket and the
      app is already wired to it.

- [ ] **A3 — A third Google project is in play.**
      `GOOGLE_WEB_CLIENT_ID` is `290591196295-…`, which matches neither of the above. The backend's
      own `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are still blank, so Google Sign-In cannot be
      verified server-side at all. Consolidate onto one project and set the backend pair.

- [ ] **A4 — TLS floor breaks older devices.** `minSdk = 24`, but TLS 1.3 is only default from
      **API 29**. The app ships no `ProviderInstaller` and no Conscrypt, so Android 7–9 devices
      cannot fetch **any** image from `img.akukas.ly` while its minimum TLS is 1.3. Proven:
      `curl --tls-max 1.2` returns `http=000`. Fix is the backend item above (set it to 1.2).

## P1 — Play Store compliance (governs the ship date)

Entirely unstarted, and mostly *not* engineering work. Google's review clock starts when you file.

- [ ] **Accessibility API declaration + demo video** — **file this first.** The app ships
      `ScreenshotAccessibilityService`; this is one of the most common rejection causes and the
      longest external wait.
- [ ] **Privacy Policy + Terms of Service**, hosted and linked. The app currently ships
      "coming soon" placeholder strings where those links belong.
- [ ] **Data Safety form** — images, DMs, account info, crash telemetry.
- [ ] **Permission justifications** — `SYSTEM_ALERT_WINDOW`,
      `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`, `FOREGROUND_SERVICE_SPECIAL_USE`,
      `FOREGROUND_SERVICE_MEDIA_PROJECTION`.
- [ ] **Store listing assets** — screenshots, feature graphic, short/long description. None exist.
- [ ] **Signed-release dry run** — `KEYSTORE_PATH`/`STORE_PASSWORD`/`KEY_PASSWORD` end to end, then
      install the signed AAB on real hardware.

## P2 — Quality before a public rollout

- [ ] **Test-coverage audit on the social surfaces.** 187 unit tests against a very large social
      codebase; auth, post-safety-check and DMs are flagged thin. Precedent: `MediaAnalysisApi`
      compiled, passed CI, and crashed the instant a user tapped "Post to Timeline".
- [ ] **Native-speaker review of the 190 Arabic strings** added to clear `MissingTranslation`.
- [ ] **Manual regression pass** using `docs/APP_SCREENS.md`.
- [ ] **Real-device background-reliability pass** — Xiaomi/Samsung OEM battery killers; the code
      exists but is unverified on hardware.
- [ ] **End-to-end against production** — enroll a device, register, upload a screenshot to R2,
      receive a push. Every backend half is verified; the client half is not.
- [ ] **Fix the local `testDebugUnitTest` OOM** so the suite is runnable off-CI.
- [ ] **Collection drag-reorder UI** — server-side `position` support already exists.

## Android — already fixed

- [x] **191 untranslated strings** → `lintDebug` went from 124 errors to **0**. Every format
      specifier verified against its English source; three lines of stray pasted text removed.
- [x] **Release builds trusted user-installed CAs** — now system-only; debug override unaffected.
- [x] **`versionCode` automation** — env-driven with a `gradle.properties` fallback, using
      configuration-cache-safe provider APIs.

---

## Deferred by owner — no action

- **`upload-keystore.jks` committed to git** (`0ed52db`). If it ever signed a published artifact,
  anyone with repo access — including history — holds it.
- **Signing key has no backup.** `my-upload-key.jks` exists on one laptop. Without Play App Signing
  enrolment, losing it means the app can never be updated.

## Accepted / deliberate

- Facebook sign-in is a fail-closed client stub; the backend endpoint exists.
- No `config/cors.php` — the only `/api/v1` consumer is a native Android app.
- On-device OCR is Latin-script only (no ML Kit Arabic model).
- Neon Object Storage / Functions / AI Gateway are `us-east-2`-only beta, unavailable in
  `eu-central-1`; R2 is used instead.
