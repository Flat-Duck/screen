# Mobile API load scenarios

The k6 suite covers feed, Explore, search, notifications, screenshot upload, messaging, analytics
ingestion, and device telemetry. It is deliberately excluded from normal CI because it creates
real screenshots/messages/events and must run only against an authorized disposable or staging
environment.

Required environment:

- `BASE_URL`: target origin, with no `/api/v1` suffix.
- `USER_TOKEN`: a staging user Sanctum token — but see **Getting a usable USER_TOKEN** below.
  A token minted with `$user->createToken()` is NOT sufficient for the analytics scenario.
- `POST_ID` and `AUTHOR_ID`: enable analytics ingestion against an accessible post.
- `CONVERSATION_ID`: enables the messaging scenario.
- `SCREENSHOT_PATH`: enables screenshot-only upload testing; use a harmless synthetic PNG.
- `DEVICE_TOKEN`: enables telemetry batches using a device-scoped token.

Example:

```bash
BASE_URL=https://staging.example.com USER_TOKEN=... POST_ID=123 AUTHOR_ID=45 \
  DURATION=5m READ_RPS=20 k6 run load/k6/mobile-api.js
```

## Getting a usable USER_TOKEN

`POST /v1/analytics/content-events` requires an **active `DeviceSession` bound to the exact token
presented** (`ContentAnalyticsController` looks up a `DeviceSession` by `personal_access_token_id`
and aborts 401 otherwise). An ad-hoc `$user->createToken()` has no such row, so the analytics
scenario fails **100% of the time** with `{"message":"An active device session is required."}` —
verified, not theoretical.

Mint the token through the real device-backed login flow instead:

```bash
# 1. Enroll a device (unauthenticated) -> device token
curl -sX POST "$BASE_URL/api/v1/devices/enroll" -H 'Content-Type: application/json' \
  -d '{"device_uuid":"<uuid>","os_name":"Android","os_version":"14","sdk_int":34,
       "app_version":"3.0","app_version_code":30,"manufacturer":"Google","model":"Pixel"}'

# 2. Log in WITH that device token -> user token backed by a DeviceSession
#    Note the field is `login`, not `email`.
curl -sX POST "$BASE_URL/api/v1/auth/login" -H 'Content-Type: application/json' \
  -H "Authorization: Bearer <device-token>" \
  -d '{"login":"loadtest@example.com","password":"<password>","device_name":"k6"}'
```

Use that second token as `USER_TOKEN`, and the device token from step 1 as `DEVICE_TOKEN`.

## Per-user rate limits cap what one token can generate

Every limiter in `RateLimiterServiceProvider` is keyed **per user**, so a run driven by a single
`USER_TOKEN` measures the rate limiter, not the application:

| Limiter | Budget | Hit by |
|---|---|---|
| `reads` | 60/min | feed, explore, notifications |
| `search` | 20/min | `search/posts` — the binding constraint |

`readJourneys` calls `search/posts` once per iteration, so **one token sustains ~0.33 read
iterations/sec** before 429s dominate. A local 40s run at `READ_RPS=1` already returned 14% failures
purely from throttling (confirmed via `X-RateLimit-Limit: 20` / `X-RateLimit-Remaining`).

To generate real load, provision a pool of test accounts and shard `USER_TOKEN` across VUs — or
raise the limits deliberately in the staging environment and record that you did, since the result
no longer reflects production behaviour either way.
Start with one tenth of expected traffic, watch `/operations`, database/Redis/storage saturation,
Horizon, and error budgets, then increase in controlled steps. Stop immediately if 5xx exceeds 1%,
p95 reads exceed 500 ms, p95 writes exceed 1 second, queue backlog grows without recovering, or
moderation/security workflows are affected. Never use production credentials or user content.

Upload and message scenarios are opt-in because they mutate state. Use a dedicated test account and
purge its data afterward through the supported account-deletion workflow. Do not bypass screenshot
validation with arbitrary files.
