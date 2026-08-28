# Runbook: Provision the production droplet (one-time)

Builds a bare Ubuntu droplet into a host this app can run on. Once done, day-to-day releases
follow `docs/runbooks/deploy.md`; this file is the server build, not the deploy.

**Target:** DigitalOcean `akukas-ubuntu-s-2vcpu-4gb-amd` — FRA1, 2 vCPU, 4 GB RAM, 80 GB disk.

---

## 0. Is this box the right size?

| Spec | Verdict |
|---|---|
| **FRA1 (Frankfurt)** | **Good.** Best mainstream DO region for Libya — roughly 40–60 ms. AMS3/LON1 are comparable; there is no closer option worth moving for. |
| **80 GB disk** | **Plenty.** Screenshots live in Cloudflare R2, not on disk. Local storage holds OS, app, PostgreSQL and logs, and every table has bounded retention (telemetry 90 d, analytics 90 d, Pulse 7 d, Telescope absent in production). |
| **2 vCPU** | **Adequate for launch, and the binding constraint under load.** Image decode (Intervention/GD) and Tesseract OCR are CPU-bound, so keep media workers at or below the vCPU count. |
| **4 GB RAM** | **Workable, but only with the tuning below — it does not fit the stock config.** |

### The RAM budget, honestly

Everything shares one box: PHP-FPM, PostgreSQL, Redis, Horizon, two Pulse daemons, Nginx.

| Component | Budget |
|---|---|
| Horizon workers (3 / 1 / 2 — see below) | ~1.0 GB ceiling |
| PHP-FPM pool (8 children) | ~0.7 GB |
| PostgreSQL | **0 GB — hosted on Neon** (see §3) |
| Redis | ~0.3 GB |
| `pulse:work` + `pulse:check` | ~0.15 GB |
| Nginx + OS | ~0.4 GB |
| **Total** | **~2.55 GB of 4 GB** (was ~3.15 GB with PostgreSQL local) |

That leaves headroom for Tesseract, which is spawned as a **subprocess** by media workers and whose
memory sits outside PHP's `memory_limit` entirely — 50–150 MB per concurrent OCR.

> **The stock Horizon config did not fit this box.** `config/horizon.php` previously hardcoded
> 6 / 2 / 4 workers, which with the per-worker ceilings in `defaults` (128 MB default and security,
> 256 MB media) reaches **~2.0 GB for queue workers alone** — roughly half the droplet, before
> PostgreSQL or PHP-FPM. Worker counts are now env-driven; `.env.production.example` ships
> **3 / 1 / 2 (~1.0 GB)**. Raise them only when you resize.

### Two upgrades worth considering

1. ~~**Managed PostgreSQL**~~ — **done: Neon.** Frees ~0.6 GB and provides PITR. Confirm the
   Neon project is in **Frankfurt (`eu-central-1`)**; see §3.
2. **8 GB droplet**, if traffic grows or you keep PostgreSQL local. 4 GB is a launch size, not a
   growth size — it will run a closed test comfortably and start hurting under real load.

Neither is required to launch. Deploy on 4 GB, watch Pulse, and resize on evidence.

---

## 1. Base hardening

```bash
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy    # copy in your key
```

Disable password and root SSH login in `/etc/ssh/sshd_config`:

```
PermitRootLogin no
PasswordAuthentication no
```

```bash
systemctl restart ssh
```

> Keep your current session open until you have confirmed you can log in as `deploy` in a second
> terminal. Locking yourself out of a fresh droplet is the classic way to lose an afternoon.

```bash
ufw default deny incoming && ufw default allow outgoing
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
apt update && apt upgrade -y
apt install -y fail2ban unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
```

### Swap — do not skip this

DigitalOcean droplets ship with **no swap**. On a 4 GB box running this stack, one traffic spike
during an OCR job means the kernel OOM-killer picks a victim — usually PostgreSQL or Horizon, both
silently.

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl -w vm.swappiness=10 && echo 'vm.swappiness=10' >> /etc/sysctl.conf
```

Swap is a safety net, not capacity. If you are routinely *using* it, resize the droplet.

---

## 2. PHP 8.4

Ubuntu 24.04 ships PHP 8.3, which **cannot run this app** — `composer.json` requires `^8.4`
(`symfony/filesystem` needs ≥ 8.4.1) and Composer's platform check fatals before boot.

```bash
add-apt-repository ppa:ondrej/php && apt update
apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-gd \
               php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
```

`php8.4-gd` (not Imagick) is correct — `ImageProcessingService` uses Intervention's GD driver.
`php8.4-redis` provides phpredis, which `REDIS_CLIENT` expects.

In `/etc/php/8.4/fpm/pool.d/www.conf`, sized for 4 GB:

```
pm = dynamic
pm.max_children = 8
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 500
```

In `/etc/php/8.4/fpm/php.ini`:

```
memory_limit = 256M
upload_max_filesize = 12M     ; > SOCIAL_UPLOADS_MAX_SIZE_BYTES (10 MB)
post_max_size = 14M
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; production: reload FPM on deploy
```

> `opcache.validate_timestamps=0` means PHP never notices changed files. You **must**
> `systemctl reload php8.4-fpm` on every deploy or you will serve the previous release forever.

---

## 3. PostgreSQL — Neon (managed)

Neon hosts the database, so **install nothing here** and skip local PostgreSQL tuning entirely.
This frees roughly 0.6 GB on the droplet and hands you automated backups and point-in-time
recovery, which is most of what `docs/runbooks/backup_restore.md` asks for.

### Check the region first — this is the one that bites

**Neon must be in `eu-central-1` (Frankfurt) to match the FRA1 droplet.** A request here runs
many queries, and each one pays the round trip. Same region is ~1–2 ms; a US region is
80–120 ms *per query*, which turns a 15-query endpoint into a second of pure network time and no
amount of application tuning will fix it. If the Neon project is not in Frankfurt, create a new
one there and migrate before you have data worth keeping.

### Compatibility — verified against this codebase

Neon offers a **pooled** endpoint (PgBouncer in transaction mode, hostname contains `-pooler`) and
a **direct** one. Transaction pooling breaks advisory locks, `LISTEN`/`NOTIFY`, session-level
`SET`, and persistent connections. This app uses **none** of them — checked. Its only locking is
`lockForUpdate()` (`EnrollDevice`, `StartDeviceSession`, `PublishMediaAnalysis`,
`SavedCollectionService`, …), and every call sits inside a `DB::transaction()`, so the locks are
transaction-scoped and pool cleanly.

So: **pooled endpoint for the app, direct endpoint for migrations and psql.**

```bash
# .env — application traffic (pooled)
DB_CONNECTION=pgsql
DB_HOST=ep-xxxx-pooler.eu-central-1.aws.neon.tech
DB_PORT=5432
DB_DATABASE=screenshut_telemetry
DB_USERNAME=<neon-user>
DB_PASSWORD=<neon-password>
DB_SSLMODE=require          # Neon refuses plaintext; without this every connection fails
```

Run schema changes through the guarded **direct** connection (drop `-pooler` from
`DB_DIRECT_HOST`), so cached configuration cannot route DDL through a transaction pooler:

```bash
deploy/database.sh migrate
```

### Scale-to-zero

Lower Neon tiers suspend compute after inactivity, and the next query pays a cold start. This app
happens to keep it warm on its own: `operations:capture-health` runs **every minute** from the
scheduler and touches the database. Do not remove that task expecting it to be free — on Neon it
is also what stops your first user of the morning waiting on a cold start.

### Connections

There is no `max_connections` to tune on your side, but the pooled endpoint still has a ceiling.
Budget: 8 PHP-FPM children + 6 Horizon workers + 2 Pulse daemons + scheduler ≈ **20 concurrent**.
That is comfortable for any Neon plan, but it is the number to check against if you raise
`HORIZON_*_MAX_PROCESSES` or `pm.max_children`.

### Backups

Neon's PITR covers the database half of `backup_restore.md`. It does **not** cover R2 media —
that runbook is explicit that database and media form one recovery set, so you still need R2
versioning and a restore drill that verifies both together.

### RAM freed

With PostgreSQL off-box the budget in §0 drops to roughly **2.5 GB of 4 GB**. You may raise
`HORIZON_MEDIA_MAX_PROCESSES` to 3 — but media work is CPU-bound (image decode plus Tesseract) on
2 vCPU, so raise it on evidence from Pulse, not on the spare RAM alone.
## 4. Redis — read the eviction note

```bash
apt install -y redis-server
```

In `/etc/redis/redis.conf`:

```
maxmemory 512mb
maxmemory-policy noeviction
appendonly yes
```

> **`noeviction` is deliberate and important.** This app puts queues, sessions, Horizon state, the
> Pulse ingest stream and the trending ZSETs in Redis **database 0**, with only the cache in
> database 1 (`config/database.php`). `maxmemory-policy` applies to the **whole instance**, not per
> database — so the usual `allkeys-lru` would let Redis evict **queued jobs**, silently losing
> security emails and media processing with no error anywhere.
>
> With `noeviction`, a full Redis fails writes loudly instead. Watch memory in Pulse; if you need
> real cache eviction later, run a *second* Redis instance on another port with `allkeys-lru` and
> point `REDIS_CACHE_DB` at it.

---

## 5. Supporting packages

```bash
apt install -y nginx tesseract-ocr git unzip
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
tesseract --version    # SOCIAL_OCR_BINARY expects this on PATH
```

Add `tesseract-ocr-ara` only if you later enable Arabic OCR; `SOCIAL_OCR_LANGUAGE` is `eng` today.

Composer needs Flux Pro credentials before `composer install` will work:

```bash
composer config --global http-basic.composer.fluxui.dev <user> <license-key>
```

---

## 6. Nginx + TLS

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d screen.yourdomain.com
```

Server block essentials — root is `public/`, and PHP goes to the 8.4 socket:

```nginx
root /var/www/screenshut-telemetry/public;
index index.php;
client_max_body_size 14M;          # must exceed post_max_size

location / { try_files $uri $uri/ /index.php?$query_string; }

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_read_timeout 60s;
}

location ~ /\.(?!well-known).* { deny all; }
```

`AppServiceProvider::boot()` calls `URL::forceScheme('https')` unconditionally, so TLS must
terminate correctly or every generated URL will be wrong.

---

## 7. Deploy the application

From here follow **`docs/runbooks/deploy.md` §1 (First install)** — env file, `composer install
--no-dev`, asset build, `migrate --force`, permissions, the four processes from `deploy/`, and the
first admin user. Do not duplicate those steps here.

---

## 8. Verify the box

```bash
free -h                    # swap present; used swap near zero at rest
sudo -u postgres psql -c "SHOW shared_buffers;"
redis-cli config get maxmemory-policy      # must be "noeviction"
php -v                     # 8.4.x
tesseract --version
supervisorctl status       # all four RUNNING
systemctl status nginx php8.4-fpm postgresql redis-server
```

Then run the post-deploy verification in `docs/runbooks/deploy.md` §3 (health endpoint, dashboard
gating, schedule list).

Finally, watch memory under real traffic for the first day:

```bash
watch -n5 'free -m; echo; ps -eo rss,comm --sort=-rss | head -15'
```

If Horizon workers are being restarted for exceeding their memory ceiling, Pulse will show it —
lower `HORIZON_*_MAX_PROCESSES` or resize. If you are consistently into swap, resize.
