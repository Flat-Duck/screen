# Runbook: Deploy

Covers a first production install and every subsequent deploy. Companion files live in
`deploy/` (supervisor units, cron entry) and `.env.production.example` (the env template).

**Assumed paths** — adjust throughout if yours differ:

| Thing | Value |
|---|---|
| App root | `/var/www/akukas` |
| PHP binary | `/usr/bin/php8.4` |
| Web/CLI user | `www-data` |

> **PHP 8.4.1+ is mandatory.** `composer.json` requires `^8.4` because `symfony/filesystem` needs
> `>= 8.4.1`. A bare `php` on this host may still be 8.2 or 8.3, which fatals on Composer's
> platform check *before the app boots at all* — the error names the platform, not your code, so
> it is easy to misread. Pin the absolute path in every cron entry and supervisor unit. Verify
> with `/usr/bin/php8.4 -v` before anything else.

---

## 1. First install

### 1.1 Environment

```bash
cp .env.production.example .env
```

Fill in every value marked `CHANGEME`. The ones that cause silent, non-obvious failure if left
blank or wrong:

| Key | If wrong |
|---|---|
| `APP_URL` | Passkeys derive their relying-party ID from it (`config/fortify.php`); a mismatch breaks passkey login for everyone |
| `MAIL_*` | Left at `log`, email verification and password resets are written to a file and never sent — with no error |
| `R2_BUCKET` privacy | Anonymous/public access bypasses account privacy even when the API rejects the viewer; the bucket must deny unauthenticated reads |
| `FIREBASE_*` | Push sending is **silently skipped**, not an error |
| `HEALTH_CHECK_SECRET` | Unset means `/up/deep` 404s for everyone, so uptime monitoring is dark |
| `PASSKEYS_USER_HANDLE_SECRET` | Defaults to `APP_KEY`; set it explicitly or a future key rotation invalidates every registered passkey |

Generate the app key **once**, on first install only:

```bash
/usr/bin/php8.4 artisan key:generate
```

> Never rotate `APP_KEY` afterwards without re-encrypting existing encrypted columns first.

### 1.2 Dependencies and assets

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
/usr/bin/php8.4 artisan storage:link
```

`--no-dev` is what keeps Telescope off this box (it is a `require-dev` package). The app is
built for that: `AppServiceProvider::registerTelescope()` is `class_exists()`-guarded, and the
`telescope:prune` schedule entry is guarded the same way, so its absence is a no-op rather than
a fatal.

### 1.3 Database

```bash
deploy/database.sh migrate
```

The wrapper refreshes Laravel's configuration cache first, runs a sanitized connectivity preflight,
and explicitly selects `pgsql_direct`. This prevents a stale cached `DB_HOST` or an inline env
override from silently routing DDL through Neon's pooler. Its output includes only the connection,
driver, host, cache state, and connectivity result—never the username, password, or URL.

On an existing install this is also what creates the `pulse_*` tables. Application requests keep
using the default pooled `pgsql` connection.

### 1.4 Permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

If a Firebase service-account JSON is used, keep it **outside** the web root, `chmod 600`, owned
by `www-data`, and point `FIREBASE_CREDENTIALS_PATH` at it.

### 1.5 Processes — all four are required

```bash
cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
cp deploy/cron/screenshut-scheduler /etc/cron.d/screenshut-scheduler
chmod 0644 /etc/cron.d/screenshut-scheduler
supervisorctl reread && supervisorctl update
```

| Process | Provides | If missing |
|---|---|---|
| `cron` → `schedule:run` | 15 scheduled tasks | Security-outbox mail stops, nothing is ever pruned, trending/recommendations go stale |
| `horizon` | All three queues | Every queued job backs up silently |
| `pulse:work` | Pulse ingest drain | Pulse records **nothing** — dashboard stays empty (only when `PULSE_INGEST_DRIVER=redis`) |
| `pulse:check` | Host CPU/memory/disk | Only the "Servers" card is lost |

Do **not** run `queue:work` alongside Horizon — it double-consumes the same queues.

### 1.6 Grant the first admin

Every dashboard (`/pulse`, `/horizon`, telemetry) is gated on `is_admin`, which is deliberately
never mass-assignable:

```bash
/usr/bin/php8.4 artisan users:make-admin you@example.com
```

---

## 2. Every deploy

```bash
cd /var/www/akukas
/usr/bin/php8.4 artisan down --retry=60                 # optional, for migrations

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
deploy/database.sh migrate

/usr/bin/php8.4 artisan config:cache
/usr/bin/php8.4 artisan route:cache
/usr/bin/php8.4 artisan view:cache
/usr/bin/php8.4 artisan event:cache

# MANDATORY — not an optimisation. FPM runs with opcache.validate_timestamps=0, so it
# never re-reads changed files. bootstrap/cache/routes-v7.php is itself a PHP file, so a
# freshly rebuilt route cache keeps serving the PREVIOUS compiled version until this runs.
sudo systemctl reload php8.4-fpm

/usr/bin/php8.4 artisan horizon:terminate
/usr/bin/php8.4 artisan pulse:restart

/usr/bin/php8.4 artisan up
```

`horizon:terminate` and `pulse:restart` are not optional: both are long-running daemons holding
the **old** code in memory. Supervisor restarts them automatically; skipping this silently runs
last release's job code against this release's database.

Caching config is what makes `.env` stop being read at runtime — re-run `config:cache` after any
env change, or the change appears to have no effect.

For the first deployment of signed media delivery, follow
[`docs/runbooks/private-media-cutover.md`](private-media-cutover.md) before reopening traffic.

---

## 3. Post-deploy verification

```bash
curl -sf https://YOUR_HOST/up
curl -sf -H "X-Health-Check-Secret: $HEALTH_CHECK_SECRET" https://YOUR_HOST/up/deep
```

Expected from `/up/deep`:

```json
{"status":"ok","checks":{"database":{"ok":true},"queue":{"ok":true,"backlog":0},"storage":{"ok":true}}}
```

It returns **503** when any dependency is down, and **404** when the secret is absent or wrong —
so a 404 here means your monitor is misconfigured, not that the app is healthy.

Then confirm the security boundary holds. Logged out, all three must refuse:

| URL | Expected |
|---|---|
| `/pulse` | 403 (never 200) |
| `/horizon` | 403 (never 200) |
| `/telescope` | **404 — it must not exist in production** |

A 200 on `/pulse` or `/horizon` while logged out means the gate is broken; treat it as an
incident and take the box out of rotation. `User` is also the mobile API's end-user principal,
so without those gates any registered app user can browse them by logging into the web
dashboard.

Finally:

```bash
supervisorctl status                       # all four RUNNING
/usr/bin/php8.4 artisan schedule:list      # 15 tasks, telescope:prune absent
```

Watch `/pulse` for a few minutes and confirm entries are arriving. An empty dashboard on a box
taking traffic almost always means `pulse:work` is not running.

---

## 4. Rollback

If the release ran migrations, decide whether each `down()` is known-good and non-destructive
**before checking out the previous tag**. When rollback is approved, inspect and roll back through
the same direct-connection guard:

```bash
deploy/database.sh status
deploy/database.sh rollback --step=1
deploy/database.sh status
```

If rollback is destructive or uncertain, do not run it; restore the tested database/media recovery
set instead. Record the migration batch, command output, operator, and UTC time.

```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
/usr/bin/php8.4 artisan config:cache route:cache view:cache event:cache
sudo systemctl reload php8.4-fpm          # mandatory — see the note in section 2
/usr/bin/php8.4 artisan horizon:terminate && /usr/bin/php8.4 artisan pulse:restart
```

**Migrations do not always roll back cleanly.** Decide deliberately between the guarded rollback
above and a restore from backup — see `docs/runbooks/backup_restore.md`. Never run `migrate:fresh` on
production; `AppServiceProvider::boot()` prohibits destructive commands there, and that guard is
there for exactly this moment.

Before production, complete the production-clone procedure in
[`docs/runbooks/neon-migration-drill.md`](neon-migration-drill.md).

---

## 5. Failure modes seen before

| Symptom | Cause |
|---|---|
| `Composer detected issues in your platform ... requires >= 8.4.1` | A bare `php` resolved to 8.2/8.3. Use the absolute 8.4 path |
| Config change has no effect | `config:cache` not re-run; cached config ignores `.env` |
| **New route 404s, or code changes don't apply, even after `route:cache`** | **`php8.4-fpm` not reloaded.** With `opcache.validate_timestamps=0` FPM serves the previously compiled bytecode. Symptom is confusing because `artisan route:list` shows the route correctly — the CLI has `validate_timestamps=1`, so it reads the real file while FPM does not |
| Pulse dashboard empty under real traffic | `pulse:work` not running with `PULSE_INGEST_DRIVER=redis` |
| Jobs queue up, nothing processes | Horizon not running, or `QUEUE_CONNECTION` is not `redis` |
| Jobs processed twice / erratically | A stray `queue:work` running alongside Horizon |
| Scheduled tasks never fire | Cron file missing a trailing newline, wrong mode, or not owned by root |
| `/up/deep` returns 404 | Secret missing or mismatched — not a health signal |
| Emails silently never arrive | `MAIL_MAILER` still `log` |
| Push silently never arrives | `FIREBASE_*` unset — skipped by design, not an error |
