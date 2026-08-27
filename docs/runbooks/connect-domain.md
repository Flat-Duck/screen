# Runbook: Connect akukas.ly (Cloudflare-proxied)

Current DNS: `A akukas.ly → 46.101.134.187`, **Proxied**. That orange cloud is the right
choice — it gives you DDoS protection, a WAF, and caching — but it changes two things that
matter, and both fail *silently* if ignored.

## Why this needs care

**Every request arrives from a Cloudflare address.** `$request->ip()` is the throttle key for
`auth-register`, `auth-login`, `auth-social` and `two-factor-challenge`, the fallback key for
every per-user limiter, and part of Fortify's login throttle. Left unhandled, all visitors
collapse onto one key: the 10/min login limit applies to the entire internet at once. Real users
get locked out; a distributed attacker barely notices. It also poisons the DeviceSession IP shown
to users on the Sessions screen and everything `AdminAuditLogger` records.

**Certificate issuance has to cross the proxy.** Let's Encrypt HTTP-01 through an orange-clouded
record is workable but fiddly, and it has to keep working on every renewal.

## The approach

Restore the real client IP **at nginx**, not in Laravel:

```
client → Cloudflare → nginx (CF-Connecting-IP → $remote_addr) → php-fpm → Laravel
```

nginx's realip module rewrites `$remote_addr` from `CF-Connecting-IP`, but only for connections
that genuinely came from a Cloudflare range. PHP then receives the true client address in
`REMOTE_ADDR`, so Laravel needs no proxy trust at all for IP correctness — leave
`TRUSTED_PROXIES=127.0.0.1`. That is strictly safer than trusting `*` in the app, where a forged
`X-Forwarded-For` would be taken at face value.

The firewall is what makes it trustworthy: with 80/443 open only to Cloudflare, nobody can reach
the origin directly to forge that header.

---

## 1. Cloudflare Origin Certificate

Dashboard → **SSL/TLS → Origin Server → Create Certificate**. Accept the defaults (RSA 2048,
`akukas.ly` + `*.akukas.ly`, 15 years). You are shown the certificate and private key **once**.

On the droplet:

```bash
sudo mkdir -p /etc/ssl/cloudflare && sudo chmod 700 /etc/ssl/cloudflare
sudo nano /etc/ssl/cloudflare/akukas.ly.pem   # paste the certificate
sudo nano /etc/ssl/cloudflare/akukas.ly.key   # paste the private key
sudo chmod 600 /etc/ssl/cloudflare/akukas.ly.key
sudo chmod 644 /etc/ssl/cloudflare/akukas.ly.pem
```

Then set **SSL/TLS → Overview → Full (strict)**.

> Do **not** use Flexible. It leaves Cloudflare→origin traffic in plaintext while showing users a
> padlock — every bearer token in this API would cross that hop unencrypted.

An Origin Certificate is only trusted by Cloudflare, which is exactly right here: nothing else is
supposed to reach the origin. It also removes renewal from the picture for 15 years.

## 2. Real client IP at nginx

```bash
sudo bash deploy/nginx/cloudflare-realip.sh
```

Fetches Cloudflare's published ranges, writes `/etc/nginx/conf.d/cloudflare-realip.conf`, and
reloads nginx only if something changed. It refuses to install a truncated fetch — a partial list
would quietly stop trusting Cloudflare and put you back to one throttle key for everyone.

Re-run it after a Cloudflare range announcement; a monthly cron is reasonable.

## 3. Site config

```bash
sudo cp deploy/nginx/akukas.ly.conf /etc/nginx/sites-available/akukas.ly
sudo ln -sf /etc/nginx/sites-available/akukas.ly /etc/nginx/sites-enabled/akukas.ly
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 4. Lock the origin to Cloudflare

```bash
sudo bash deploy/nginx/cloudflare-firewall.sh
```

SSH is untouched. **Trade-off to accept deliberately:** after this the site is reachable *only*
through Cloudflare — grey-clouding the record, or a Cloudflare outage, takes the site down rather
than exposing the origin. That is the intended bargain.

## 5. Cloudflare settings

| Setting | Value | Why |
|---|---|---|
| SSL/TLS mode | **Full (strict)** | Encrypts the Cloudflare→origin hop and validates the Origin Cert |
| Always Use HTTPS | **On** | |
| Minimum TLS | **1.2** | |
| Cache Rule: `/api/*` | **Bypass cache** | A cached authenticated API response served to the wrong user is a data leak. Cloudflare does not cache these by default, but an explicit rule removes the chance |
| Brotli | On | |

Leave Rocket Loader and Auto Minify off — the app's assets are already built by Vite, and
Rocket Loader reorders scripts in ways Livewire dislikes.

## 6. Verify

```bash
curl -sI https://akukas.ly/up | head -3          # 200 through Cloudflare
curl -sI --resolve akukas.ly:443:46.101.134.187 https://akukas.ly/up   # should now TIME OUT
```

The second failing is the point — it proves the origin is no longer directly reachable.

Then confirm the real client IP is landing, which is the whole reason for steps 2 and 4:

```bash
sudo tail -5 /var/log/nginx/akukas.access.log
```

Those addresses must be **real client IPs**, not `172.x` / `104.x` Cloudflare addresses. If you
see Cloudflare ranges there, stop — the rate limiters are keyed on one bucket for everyone, and
step 2 did not take effect.
