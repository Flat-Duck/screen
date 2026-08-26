# Production Roadmap

**Companion to [CHECKLIST.md](CHECKLIST.md).** Analysis date: 2026-08-26.

The backend is in good shape — the work there is closing a CI correctness gap and adding
operational visibility. The Android app is where the real critical path lies, and within it the
Play Store compliance track (A6) is the longest pole: it is mostly *waiting on external review and
on your own legal content*, not on engineering. **Start A6 first, on day one, and let it run in
parallel with everything else.**

---

## Stage 0 — Stop the bleeding (day 1)

Two items are actively dangerous and one gates every future CI signal. Nothing else should start
until these are moving.

| # | Task | Repo | Why now |
|---|---|---|---|
| A1 | Purge `upload-keystore.jks` from git history; assess whether it ever signed a published artifact; rotate if so | android | A private signing key is sitting in the repo and in every clone's history |
| A2 | Enrol in Play App Signing; back up `my-upload-key.jks` to secure escrow; write a key-recovery runbook | android | The active key exists on one laptop. Lose it and the app becomes unupdatable |
| B1 | Bump `composer.json` to `^8.4`, drop `8.3` from the CI matrix, move the Postgres job to the `8.4` leg | backend | CI's Postgres leg cannot install dependencies — the production DB engine is untested |

**Exit criteria:** the keystore is out of history and backed up; a green backend CI run exists that
actually executed the PostgreSQL suite.

---

## Stage 1 — Get both pipelines green (week 1)

| # | Task | Repo | Notes |
|---|---|---|---|
| A3 | Translate the 191 missing Arabic strings, or make an explicit documented decision on `MissingTranslation` | android | Currently fails `lintDebug`, so Android CI is red |
| A4 | Build + test the 5 unpushed commits on a JDK-21 machine, then push | android | The R2 upload protocol has never been compiled by CI |
| B3 | Push the R2/OCR backend commit through the now-correct CI | backend | |
| A5 | Remove `<certificates src="user" />` from the release network security config | android | Small change, closes a token-interception path |

> **Environment prerequisite:** this analysis machine has no JDK. Install JDK 21 (or use Android
> Studio's bundled JBR) before Stage 1 — none of the Android items can be verified without it.

**Exit criteria:** both repos green on CI, nothing unpushed, no known-red checks.

---

## Stage 2 — Store compliance, started in parallel from day 1 (weeks 1–4)

This is the critical path. Google's review of an Accessibility API declaration is not fast, and the
legal content is yours to write — neither compresses under pressure.

| # | Task | Owner | Notes |
|---|---|---|---|
| A6a | Privacy Policy + Terms of Service, hosted and linked in-app | you / legal | Not a code task. Must cover screenshots, DMs, telemetry, account data |
| A6b | **Accessibility API declaration + demo video** | you | The app ships `ScreenshotAccessibilityService`. Highest rejection risk on the whole list — file it first |
| A6c | Data Safety form | you | Images, DMs, account info, crash telemetry |
| A6d | Justify `SYSTEM_ALERT_WINDOW`, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`, `FOREGROUND_SERVICE_SPECIAL_USE`, `FOREGROUND_SERVICE_MEDIA_PROJECTION` | you | Each is policy-sensitive |
| A6e | Store listing assets: screenshots, feature graphic, short/long description | you / design | None exist |
| A6f | Signed-release dry run — `KEYSTORE_PATH`/`STORE_PASSWORD`/`KEY_PASSWORD` end-to-end, install the signed AAB on a real device | eng | Depends on A2 |

**Exit criteria:** a signed release build installs on real hardware, and every Play Console
declaration is submitted.

---

## Stage 3 — Operational readiness (weeks 2–3, parallel with Stage 2)

The backend can serve traffic today; this stage is about knowing when it stops.

| # | Task | Repo |
|---|---|---|
| B2 | Add an error tracker (Sentry or equivalent); wire `/up/deep` to an uptime monitor with `HEALTH_CHECK_SECRET`; define alert routing | backend |
| B4 | Reconcile `README.md`'s `queue:work` instructions with the actual Horizon supervisors | backend |
| B5 | Codify a production env template + a deploy-time assertion on `APP_DEBUG=false` / `APP_ENV=production` | backend |
| B8 | Verify the `schedule:run` cron and Horizon supervisors are live on the production host — **pinned to PHP 8.4+, not 8.3** | ops |
| B7 | Execute `docs/runbooks/backup_restore.md` as a live drill | ops |
| B6 | Run the k6 suite against staging; record a baseline against its p95/error thresholds | backend |

**Exit criteria:** a deliberately broken endpoint pages someone; a restore has actually been
performed from backup.

---

## Stage 4 — Quality pass (weeks 3–5)

| # | Task | Repo |
|---|---|---|
| A7 | Test-coverage audit on social surfaces — prioritise auth, post-safety-check, DMs | android |
| A8 | Manual regression pass against `docs/APP_SCREENS.md` | android |
| A9 | Real-device background-reliability pass on Xiaomi + Samsung hardware | android |
| A10 | Automate `versionCode` bumping | android |
| A11 | Collection drag-reorder UI | android |

---

## Stage 5 — Launch (weeks 5–8)

1. **Internal testing track** — team-only, signed release build, smoke the full journey.
2. **Closed testing** — a small external group. Watch Crashlytics.
3. **Crashlytics stabilization window** — hold here until the crash-free rate is stable across at
   least one full week of real usage. Do not compress this; the app's own readiness doc records a
   crash class that only surfaced on real devices.
4. **Staged production rollout** — 5% → 20% → 50% → 100%, with a documented rollback trigger.

---

## Critical path at a glance

```
Day 1        A1, A2, B1  ──┐
                           ├─→ Stage 1 (green CI) ──→ Stage 4 (quality) ──┐
Day 1 also   A6b filed  ──┘                                               ├─→ Stage 5 (launch)
             └─→ Stage 2 (Play review, external latency) ─────────────────┘
             └─→ Stage 3 (ops, parallel) ───────────────────────────────────┘
```

**The schedule is governed by Google's review turnaround on A6b and by how quickly the legal
content in A6a lands — not by engineering throughput.** File the Accessibility declaration on day
one, in parallel with the Stage 0 security work.

## Honest estimate

| Path | Estimate |
|---|---|
| Engineering work only (Stages 0, 1, 3, 4) | **3–5 weeks** |
| Including Play compliance + review latency (Stage 2) | **5–8 weeks** |
| To full staged rollout (Stage 5) | **7–10 weeks** |

The backend could ship in roughly a week once B1 and B2 are done. The Android app is the
constraint, and Play compliance is the constraint within it.
