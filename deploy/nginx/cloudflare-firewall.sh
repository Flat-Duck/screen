#!/usr/bin/env bash
#
# Restricts inbound 80/443 to Cloudflare's published ranges.
# Run as: sudo bash deploy/nginx/cloudflare-firewall.sh
#
# Two things this buys:
#   1. Nobody can reach the origin directly at 46.101.134.187 and bypass Cloudflare's
#      WAF/DDoS protection.
#   2. It makes the real-IP setup trustworthy. nginx replaces $remote_addr from the
#      CF-Connecting-IP header; if anyone could connect to the origin directly they
#      could forge that header, and the auth rate limiters key on the result.
#
# Trade-off, deliberately: once this is applied the site is reachable ONLY through
# Cloudflare. Grey-clouding the DNS record (or a Cloudflare outage) takes the site
# offline rather than exposing the origin. Re-run after Cloudflare changes ranges.
#
# SSH (22) is untouched — you will not lock yourself out.
set -euo pipefail

command -v ufw >/dev/null || { echo "ufw not installed"; exit 1; }

TMP=$(mktemp)
for url in https://www.cloudflare.com/ips-v4 https://www.cloudflare.com/ips-v6; do
  curl -fsS "$url" >> "$TMP"; echo >> "$TMP"
done
COUNT=$(grep -c '[0-9a-fA-F]' "$TMP" || true)

if [ "$COUNT" -lt 10 ]; then
  echo "Refusing to apply: only $COUNT ranges fetched (expected 20+). Firewall unchanged." >&2
  rm -f "$TMP"; exit 1
fi

echo "Fetched $COUNT Cloudflare ranges."

# Drop any previous 80/443 rules so re-running does not pile up duplicates.
while ufw status numbered | grep -qE '(^|\s)(80|443)(/tcp)?\s'; do
  N=$(ufw status numbered | grep -E '(^|\s)(80|443)(/tcp)?\s' | head -1 | sed -E 's/^\[\s*([0-9]+)\].*/\1/')
  ufw --force delete "$N" >/dev/null
done

while read -r cidr; do
  [ -n "$cidr" ] || continue
  ufw allow from "$cidr" to any port 80  proto tcp >/dev/null
  ufw allow from "$cidr" to any port 443 proto tcp >/dev/null
done < "$TMP"
rm -f "$TMP"

ufw reload >/dev/null
echo "Applied. 80/443 now reachable only from Cloudflare; SSH untouched."
ufw status | grep -cE '(80|443)' | xargs echo "  active 80/443 rules:"
