# Production Roadmap

**Companion to [CHECKLIST.md](CHECKLIST.md).** First analysis 2026-08-26; updated 2026-08-27
after the first fix pass landed.

## Where things stand

Stage 0 and Stage 1 are **done**. The backend's blocking engineering work is closed: CI now
actually tests PostgreSQL, dependencies are clean and audited on every build, and production
monitoring exists (Pulse) with Telescope demoted to a dev-only tool. On Android, CI is green
again and the code-level fixes have landed.

**What remains is mostly not code.** The critical path is Play Store compliance — legal content
you have to write and declarations Google has to review. Neither compresses under pressure.
Everything else is operational: wiring alerts, running drills, pushing for a CI verification.

---

## Stage 0 — Stop the bleeding ✅ done

| # | Task | Status |
|---|---|---|
| B1 | `composer.json` → `^8.4`, matrix → `['8.4','8.5']`, Postgres job moved onto a leg that can install | ✅ |
| — | 12 dependency advisories (incl. one high-severity Guzzle host-check bypass) cleared; `composer audit` added to CI | ✅ |
| A1/A2 | Keystore purge + Play App Signing enrolment | ⏭️ **deferred by owner** |

## Stage 1 — Green pipelines ✅ done

| # | Task | Status |
|---|---|---|
| A3 | 190 Arabic strings translated; `lintDebug` green (0 errors, down from 124) | ✅ |
| A5 | Release network config no longer trusts user CAs | ✅ |
| A10 | `versionCode`/`versionName` driven by env + `gradle.properties`, configuration-cache safe | ✅ |
| B2 | Pulse installed, admin-gated, Redis ingest; Telescope moved to `require-dev` | ✅ |
| B4/B5 | README + `CLAUDE.md` reconciled with Horizon; `.env.production.example` written | ✅ |

Verified locally: backend 627/629 tests, PHPStan 0, Pint clean, contract current, audit clean.
Android `assembleDebug` + `lintDebug` + `verifyRoborazziDebug` all pass.

---

## Stage 2 — Play Store compliance ⬅️ **the critical path, start today**

Nothing here is blocked by engineering. Google's review of an Accessibility API declaration takes
real calendar time, and the legal content is yours to write.

| # | Task | Owner | Notes |
|---|---|---|---|
| A6b | **Accessibility API declaration + demo video** | you | **File this first.** The app ships `ScreenshotAccessibilityService`; highest rejection risk on the list |
| A6a | Privacy Policy + Terms of Service, hosted and linked | you / legal | The app currently ships "coming soon" placeholders for both — those strings need real URLs |
| A6c | Data Safety form | you | Images, DMs, account info, crash telemetry |
| A6d | Permission justifications | you | `SYSTEM_ALERT_WINDOW`, battery-optimisation exemption, both foreground-service types |
| A6e | Store listing assets | you / design | Screenshots, feature graphic, short/long description — none exist |
| A6f | Signed-release dry run + real-device install | eng | Uses `KEYSTORE_PATH`/`STORE_PASSWORD`/`KEY_PASSWORD`; note A2 is deferred |

**Exit criteria:** a signed release build installs on real hardware and every Play Console
declaration is submitted.

---

## Stage 3 — Operational readiness (parallel, ~1 week)

The backend can serve traffic today. This stage is about knowing when it stops.

| # | Task |
|---|---|
| — | **Deploy**: copy `.env.production.example` → `.env`, fill the `CHANGEME`s, `migrate --force` (creates the `pulse_*` tables), cache config/routes/views/events |
| — | **Start the four processes**: scheduler cron, `horizon`, `pulse:work`, `pulse:check` — all pinned to PHP 8.4+ (a bare `php` may be 8.2/8.3 and will now fatal) |
| — | **Wire alerting.** Pulse is a dashboard, not a pager. Point an uptime monitor at `/up/deep` with `HEALTH_CHECK_SECRET` and decide who gets woken up |
| B3/A4 | Push both repos; confirm green CI, including the PostgreSQL job that has never run |
| B7 | Execute `docs/runbooks/backup_restore.md` as a live drill |
| B6 | Run the k6 suite against staging; record a baseline against its p95/error thresholds |

**Exit criteria:** a deliberately broken endpoint pages someone, and a restore has actually been
performed from backup.

---

## Stage 4 — Quality pass (~2 weeks, parallel with Stage 2)

| # | Task |
|---|---|
| — | Native-speaker review of the 190 new Arabic strings |
| A7 | Test-coverage audit on social surfaces — auth, post-safety-check, DMs |
| A8 | Manual regression pass against `docs/APP_SCREENS.md` |
| A9 | Real-device background-reliability pass (Xiaomi + Samsung) |
| — | Fix the local Robolectric crash so the unit suite is runnable off-CI |
| A11 | Collection drag-reorder UI |

---

## Stage 5 — Launch

1. **Internal testing track** — team-only signed build, smoke the full journey.
2. **Closed testing** — small external group, watch Crashlytics.
3. **Stabilization window** — hold until the crash-free rate is stable across a full week of real
   usage. Do not compress this: the app's own readiness doc records a crash class
   (`MediaAnalysisApi`'s Retrofit wildcard) that only surfaced on real devices.
4. **Staged production rollout** — 5% → 20% → 50% → 100%, with a documented rollback trigger.

---

## Critical path

```
DONE ──→ Stage 0 (blockers)  ✅
         Stage 1 (green CI)  ✅
                              │
NOW  ────────────────────────┼──→ Stage 2  Play compliance ──┐  ← governs the date
                             ├──→ Stage 3  ops/deploy        ├──→ Stage 5  launch
                             └──→ Stage 4  quality           ┘
```

Stages 2, 3, and 4 run concurrently. The ship date is set by Google's review turnaround on the
Accessibility declaration and by how fast the Privacy Policy and Terms land — not by engineering.

## Revised estimate

| Path | Estimate |
|---|---|
| Backend to production | **~1 week** (deploy + wire alerting + drills) |
| Android engineering (Stage 4) | **2–3 weeks** |
| Play compliance + review latency (Stage 2) | **3–6 weeks**, mostly waiting |
| To full staged rollout | **5–8 weeks** |

Down from the original 7–10 weeks: Stages 0 and 1 are closed, and the backend is deployable now.
