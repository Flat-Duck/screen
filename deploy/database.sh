#!/usr/bin/env bash

set -euo pipefail

action="${1:-}"
if [[ -z "$action" ]]; then
  echo "Usage: deploy/database.sh {migrate|status|rollback} [artisan options]" >&2
  exit 64
fi
shift

php_bin="${PHP_BIN:-/usr/bin/php8.4}"
if [[ ! -x "$php_bin" ]]; then
  echo "PHP binary is not executable: $php_bin" >&2
  exit 69
fi

# Refresh cached configuration before reading DB_DIRECT_HOST. Without this, Artisan can use the
# previous deployment's cached host even after .env has changed.
"$php_bin" artisan config:cache
"$php_bin" artisan database:migration-preflight --database=pgsql_direct

case "$action" in
  migrate)
    exec "$php_bin" artisan migrate --database=pgsql_direct --force "$@"
    ;;
  status)
    exec "$php_bin" artisan migrate:status --database=pgsql_direct "$@"
    ;;
  rollback)
    exec "$php_bin" artisan migrate:rollback --database=pgsql_direct --force "$@"
    ;;
  *)
    echo "Unknown database action: $action" >&2
    exit 64
    ;;
esac
