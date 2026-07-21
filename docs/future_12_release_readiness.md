# API Contracts, Load Testing, and Production Runbooks

Milestone 7.3 closes the planned backend roadmap with executable release-safety artifacts. These
artifacts are versioned with the code so API drift, performance assumptions, and incident procedures
are reviewable changes rather than undocumented operational knowledge.

## OpenAPI and mobile models

`docs/openapi-v1.json` is generated from Laravel's registered `/api/v1` routes by:

```text
php artisan api:export-contract
```

The generator emits every path/method, path parameter, operation ID, rate/error responses, bearer
authentication, and the required principal (`public`, `device`, or `user`). It includes strict core
models for device enrollment, telemetry batches/events/errors, content analytics, screenshot post
creation, messages, users, post media, posts, envelopes, and post pages. Less mature endpoint bodies
remain explicit `JsonObject` extension points rather than pretending to be more precise than the
backend contract currently guarantees.

`php artisan api:export-contract --check` regenerates the document in memory and performs a byte-for-
byte drift check. It is part of `composer test`. Feature tests additionally enforce complete route
coverage, unique operation IDs, valid internal references, request-rule alignment for critical
device/telemetry models, and compatibility of real user/post resources with required schema fields.

Recommended mobile workflow:

1. Generate Android/Dart models and API clients from the committed OpenAPI document in the mobile
   repository; do not generate from a deployed server at build time.
2. Review generated diffs whenever this backend contract changes.
3. Keep tolerant readers for optional fields and reject unknown enum values into an `unknown` client
   state so a server rollout does not crash older mobile builds.
4. Run mobile serialization fixtures and staging contract smoke tests before releasing either side.

## Load testing

`load/k6/mobile-api.js` defines separate read, analytics, messaging, screenshot-upload, and telemetry
scenarios. Reads run by default once `BASE_URL` and `USER_TOKEN` are supplied; mutation/device flows
activate only when their required IDs, file, or token are explicitly present. Thresholds fail on 1%
request errors, read p95 above 500 ms, write p95 above one second, or checks below 99%.

The script is intentionally not part of ordinary CI and must never be aimed at production without
written incident/change authority. Follow `load/README.md` for staging setup, ramp strategy, stop
conditions, and cleanup.

## Runbooks and drills

- `docs/runbooks/backup_restore.md`
- `docs/runbooks/queue_scheduler_outage.md`
- `docs/runbooks/moderation_escalation.md`
- `docs/runbooks/account_compromise.md`
- `docs/runbooks/data_deletion_incident.md`

Runbooks are starting procedures, not substitutes for environment-specific infrastructure commands,
legal policy, secret-manager access, alert routing, or named on-call ownership. Before production,
replace organizational placeholders, execute the backup/restore drill, simulate a stopped scheduler
and worker, and record measured RPO/RTO plus remediation owners.

## Release gate

Before launch, require a green `composer test`, reviewed OpenAPI/mobile generated diff, successful
staging load run at expected peak plus safety margin, restored-backup application smoke test, queue
and scheduler recovery drill, moderation/security escalation contacts, and verified account/media
deletion behavior. The roadmap is implemented; these environment-dependent drills remain release
operations rather than code tasks.
