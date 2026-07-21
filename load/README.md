# Mobile API load scenarios

The k6 suite covers feed, Explore, search, notifications, screenshot upload, messaging, analytics
ingestion, and device telemetry. It is deliberately excluded from normal CI because it creates
real screenshots/messages/events and must run only against an authorized disposable or staging
environment.

Required environment:

- `BASE_URL`: target origin, with no `/api/v1` suffix.
- `USER_TOKEN`: a staging user Sanctum token.
- `POST_ID` and `AUTHOR_ID`: enable analytics ingestion against an accessible post.
- `CONVERSATION_ID`: enables the messaging scenario.
- `SCREENSHOT_PATH`: enables screenshot-only upload testing; use a harmless synthetic PNG.
- `DEVICE_TOKEN`: enables telemetry batches using a device-scoped token.

Example:

```bash
BASE_URL=https://staging.example.com USER_TOKEN=... POST_ID=123 AUTHOR_ID=45 \
  DURATION=5m READ_RPS=20 k6 run load/k6/mobile-api.js
```

Start with one tenth of expected traffic, watch `/operations`, database/Redis/storage saturation,
Horizon, and error budgets, then increase in controlled steps. Stop immediately if 5xx exceeds 1%,
p95 reads exceed 500 ms, p95 writes exceed 1 second, queue backlog grows without recovering, or
moderation/security workflows are affected. Never use production credentials or user content.

Upload and message scenarios are opt-in because they mutate state. Use a dedicated test account and
purge its data afterward through the supported account-deletion workflow. Do not bypass screenshot
validation with arbitrary files.
