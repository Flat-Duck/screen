#!/usr/bin/env bash
#
# One-time system provisioning for the production droplet.
# Run as:  sudo bash deploy/provision.sh
#
# Idempotent — safe to re-run. It does SYSTEM setup only:
#   swap, PHP 8.4, Redis hardening, Nginx, Tesseract, Supervisor, firewall.
#
# It deliberately does NOT touch .env, secrets, the database, composer install,
# migrations, or the Nginx site block (that needs your domain). Those are in
# docs/runbooks/deploy.md and are run as `deploy`, not root.
#
set -euo pipefail

APP_DIR=/var/www/akukas
APP_USER=deploy
WEB_USER=www-data

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[0;32m✓\033[0m %s\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Run with sudo."; exit 1; }

# ---------------------------------------------------------------- 1. swap
say "Swap (DigitalOcean droplets ship with none)"
if swapon --show | grep -q .; then
  ok "swap already active"
else
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  ok "2G swapfile created and enabled"
fi
sysctl -qw vm.swappiness=10
grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
ok "vm.swappiness=10 (swap is a safety net, not capacity)"

# ------------------------------------------------------------- 2. php 8.4
say "PHP 8.4 (Ubuntu 24.04 ships 8.3, which cannot run this app)"
if ! command -v php8.4 >/dev/null; then
  apt-get update -qq
  apt-get install -y -qq software-properties-common
  add-apt-repository -y ppa:ondrej/php >/dev/null
  apt-get update -qq
fi
apt-get install -y -qq \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-gd php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-opcache
ok "php8.4 + extensions installed"

# Make the CLI default 8.4 so `php artisan` is never accidentally 8.3.
update-alternatives --set php /usr/bin/php8.4 >/dev/null 2>&1 || true
ok "CLI default: $(php -v | head -1)"

say "PHP tuning for 2 vCPU / 4 GB"
cat > /etc/php/8.4/fpm/conf.d/99-akukas.ini <<'INI'
; Sized for a 2 vCPU / 4 GB droplet with Redis, Horizon and Pulse on the same box.
memory_limit = 256M
; Must exceed SOCIAL_UPLOADS_MAX_SIZE_BYTES (10 MB) with headroom for multipart overhead.
upload_max_filesize = 12M
post_max_size = 14M
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
; Production: PHP never stats files for changes, so deploys MUST reload php8.4-fpm.
opcache.validate_timestamps = 0
INI
cp /etc/php/8.4/fpm/conf.d/99-akukas.ini /etc/php/8.4/cli/conf.d/99-akukas.ini
# CLI must notice file changes (artisan, migrations) — only FPM freezes timestamps.
sed -i 's/^opcache.validate_timestamps = 0/opcache.validate_timestamps = 1/' /etc/php/8.4/cli/conf.d/99-akukas.ini
ok "99-akukas.ini written (fpm + cli)"

POOL=/etc/php/8.4/fpm/pool.d/www.conf
cp -n "$POOL" "$POOL.orig" 2>/dev/null || true
sed -i \
  -e 's/^pm = .*/pm = dynamic/' \
  -e 's/^pm.max_children = .*/pm.max_children = 8/' \
  -e 's/^pm.start_servers = .*/pm.start_servers = 3/' \
  -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
  -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 4/' \
  -e 's/^;\?pm.max_requests = .*/pm.max_requests = 500/' \
  "$POOL"
ok "FPM pool: 8 max_children (~0.7 GB ceiling)"

# Retire 8.3 so nothing can accidentally serve on the wrong runtime.
if systemctl is-enabled php8.3-fpm >/dev/null 2>&1; then
  systemctl disable --now php8.3-fpm
  ok "php8.3-fpm stopped and disabled"
fi
systemctl enable --now php8.4-fpm >/dev/null
systemctl restart php8.4-fpm
ok "php8.4-fpm running"

# ---------------------------------------------------------------- 3. redis
say "Redis hardening"
RC=/etc/redis/redis.conf
cp -n "$RC" "$RC.orig" 2>/dev/null || true
# maxmemory 0 (the default) means Redis will happily consume the whole droplet.
if grep -qE '^\s*maxmemory\s' "$RC"; then
  sed -i 's/^\s*maxmemory\s.*/maxmemory 512mb/' "$RC"
else
  echo 'maxmemory 512mb' >> "$RC"
fi
# noeviction is REQUIRED, not a preference: queues, sessions, Horizon state, the Pulse
# ingest stream and trending ZSETs all live in Redis db0. maxmemory-policy is instance-wide,
# so allkeys-lru would silently evict QUEUED JOBS.
if grep -qE '^\s*maxmemory-policy\s' "$RC"; then
  sed -i 's/^\s*maxmemory-policy\s.*/maxmemory-policy noeviction/' "$RC"
else
  echo 'maxmemory-policy noeviction' >> "$RC"
fi
# Without AOF, a Redis restart loses every queued job, session and pending Pulse entry.
sed -i 's/^\s*appendonly\s.*/appendonly yes/' "$RC" || echo 'appendonly yes' >> "$RC"
systemctl restart redis-server
ok "maxmemory=512mb  policy=noeviction  appendonly=yes"

# ------------------------------------------------------- 4. other packages
say "Nginx, Tesseract, Supervisor, certbot, psql client"
apt-get install -y -qq nginx supervisor tesseract-ocr certbot python3-certbot-nginx \
  postgresql-client unzip git
systemctl enable --now nginx supervisor >/dev/null
ok "installed: nginx $(nginx -v 2>&1 | sed 's/.*\///'), $(tesseract --version 2>&1 | head -1)"

# -------------------------------------------------------------- 5. firewall
say "Firewall"
ufw --force default deny incoming >/dev/null
ufw --force default allow outgoing >/dev/null
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null
ok "ufw: 22/80/443 in, all else denied"

apt-get install -y -qq unattended-upgrades >/dev/null
ok "unattended-upgrades installed"

# ----------------------------------------------------------- 6. permissions
say "Application directory permissions"
if [[ -d $APP_DIR ]]; then
  chown -R "$APP_USER:$WEB_USER" "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 2750 {} \;
  find "$APP_DIR" -type f -exec chmod 640 {} \;
  chmod 750 "$APP_DIR/artisan"
  # Only these two need to be writable by the web/worker user.
  mkdir -p "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  chmod -R 2770 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  ok "$APP_DIR owned $APP_USER:$WEB_USER; storage + bootstrap/cache group-writable"
else
  echo "    ! $APP_DIR not found — skipping"
fi

say "System provisioning complete"
cat <<'NEXT'
    Next (as `deploy`, NOT root) — see docs/runbooks/deploy.md:
      1. git pull                       (the checkout is behind origin/main)
      2. cp .env.production.example .env && fill in Neon + R2 + mail + secrets
      3. composer install --no-dev --optimize-autoloader
      4. npm ci && npm run build
      5. php artisan key:generate       (first install only)
      6. deploy/database.sh migrate     (preflight + explicit pgsql_direct connection)
      7. php artisan config:cache route:cache view:cache event:cache
      8. sudo cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
         sudo cp deploy/cron/screenshut-scheduler /etc/cron.d/
         sudo chmod 0644 /etc/cron.d/screenshut-scheduler
         sudo supervisorctl reread && sudo supervisorctl update
      9. Nginx site block + certbot (needs your domain) — deploy.md §6
NEXT
