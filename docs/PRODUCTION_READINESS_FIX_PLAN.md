# Production Readiness Fix Plan

This plan coordinates production-readiness work across:

- Backend: `screenshut-telemetry`
- Android: `screenshot-detector`

Both repositories use the `codex/production-readiness` branch for this work. Complete the items in order unless a blocker explicitly requires external action.

## Working rules

- Make one focused fix at a time and verify it before starting the next.
- Preserve the existing Android icon, string, and manifest work unless a task explicitly overlaps it.
- Add or update automated tests for every behavior change.
- Do not commit credentials, signing keys, generated secrets, or production environment files.
- Keep Android request models and the backend OpenAPI contract synchronized.
- Record external console work separately from repository changes.

## Phase 1: Immediate release blockers

### 1. Normalize and validate the Android API base URL

Repository: Android

- [x] Correct the example base URL so it ends with `/`.
- [x] Normalize configured URLs before passing them to Retrofit.
- [x] Reject blank, malformed, and non-HTTPS release URLs with a useful error.
- [x] Add unit tests for URLs with and without a trailing slash.
- [x] Build the debug app and construct Retrofit using the configured debug URL.

Acceptance criteria:

- Retrofit receives a valid trailing-slash base URL for every supported configuration.
- A bad release configuration fails with an actionable message.
- Relevant unit tests and Android lint pass.

### 2. Contain and rotate the tracked Android signing key

Repositories/external systems: Android, Git hosting, Google Play Console, CI secrets

- [x] Confirm `upload-keystore.jks` never signed a distributed build and permanently retire it.
- [ ] Back up the real `my-upload-key.jks` signing material in an approved secret manager.
- [x] Record that Google Play rotation is not required because the retired key was never used.
- [x] Remove the retired key from the current tree; no history rewrite is required for this unused key.
- [x] Make the Gradle signing configuration use documented CI/local secret inputs.
- [x] Add a repository/CI guard that rejects tracked keystore files.
- [ ] Verify a signed release bundle using the replacement configuration.

Acceptance criteria:

- No usable private signing key remains in the current tree or reachable Git history.
- Release signing succeeds using secret-managed inputs.
- The installed release identity matches the intended Play application identity.

### 3. Protect private and restricted media

Repository: Backend

- [x] Define access rules for posts, private saves, avatars, group photos, archives, and deleted media.
- [x] Configure production media and direct uploads for a private R2 bucket with no public URL.
- [x] Return twenty-minute, viewer-bound signed URLs through authorization-checked endpoints.
- [x] Recheck blocks, privacy, membership, archive, deletion, and ownership when media is fetched.
- [x] Define bounded public caching and `no-store` for restricted media.
- [x] Add authorization, revocation, tampering, and expired-URL feature tests.
- [x] Document the zero-public-exposure R2 cutover and legacy-object verification procedure.
- [ ] Apply the production R2 cutover and confirm an old permanent URL is anonymously denied.

Acceptance criteria:

- Possession of a stale object URL is insufficient to access restricted media.
- Public media remains cacheable without weakening private-media authorization.

### 4. Complete Android permission and Google Play disclosures

Repository/external system: Android and Google Play Console

- [x] Declare `READ_MEDIA_VISUAL_USER_SELECTED` and add Android 14+ full/partial/revoked access tests.
- [x] Implement a prominent accessibility disclosure shown before opening system settings.
- [x] Capture versioned affirmative consent independently of the Android permission screen.
- [x] Explain screenshot capture, gesture use, data collection, upload, retention, and opt-out behavior.
- [x] Add graceful behavior for denied, limited, revoked, and permanently denied access.
- [x] Prepare AccessibilityService, broad photo-access, and special-use foreground-service declarations in the Android submission packet.
- [ ] Record the required demonstration videos and ensure store copy matches actual behavior.
- [ ] Approve the drafted Data Safety answers after resolving the hosted privacy-policy mismatches and verifying Firebase production settings.
- [x] Run the new Android tests and Android lint with the configured `/Volumes/devDSK` toolchain.

Acceptance criteria:

- Permission flows work on Android 13, 14, 15, and 16.
- In-app disclosures and Play Console declarations describe the same behavior.
- A user can decline without being trapped in a settings loop.

### 5. Run production migrations over the direct Postgres connection

Repository: Backend

- [x] Update deployment and rollback commands to select `pgsql_direct` explicitly.
- [x] Refresh and validate configuration caching before migrations so a stale pooled host cannot be reused.
- [x] Add a safe preflight that identifies the selected connection without printing credentials.
- [x] Exercise deploy, rollback, recovery, and schema diff on an expiring Neon child cloned from production.
- [x] Update CI and deployment automation to enforce the direct migration connection.
- [ ] Enable Neon branch protection for `production` (reported `protected=false` during the drill).

Acceptance criteria:

- Application traffic uses the pooled connection.
- Schema migrations and operational DDL use the direct connection.
- The runbook has a tested rollback path.

### 6. Establish email ownership and account recovery

Repositories: Backend and Android

- [x] Decide which actions require a verified email address.
- [x] Prevent an unverified registration from permanently claiming another person's address.
- [x] Add mobile verification, resend, forgot-password, and reset-password flows.
- [x] Define behavior for social accounts without a password.
- [x] Rate-limit verification and recovery endpoints and avoid account enumeration.
- [x] Add backend feature tests and Android UI/repository tests.
- [ ] **Side note — deferred:** execute the new Android tests and lint after the `/Volumes/devDSK` build JDK is reconnected.
- [ ] Publish Android App Links `assetlinks.json` with the production signing fingerprint (manual/external release task).

Acceptance criteria:

- Users can prove email ownership and recover password accounts securely.
- Responses do not reveal whether an email address is registered.

## Phase 2: Reliability and security

### 7. Preserve telemetry across transient outages

- [x] Separate permanent payload failures from retryable network/server failures.
- [x] Stop deleting crash data solely because a transient retry count was reached.
- [x] Bound storage using age and byte limits with explicit eviction priority.
- [x] Add exponential backoff with jitter and tests for offline, timeout, 429, and 5xx cases.
- [ ] **Side note — deferred:** execute the telemetry unit tests and Android lint after the Android SDK/JDK toolchain is available.

### 8. Enforce or remove Firebase App Check

- [x] Verify App Check tokens server-side on enrollment/auth/write endpoints.
- [x] Support staged enforcement, structured metrics, and development/debug tokens.
- [x] Define fail-closed behavior with cached keys during Firebase verification outages.
- [x] Retain the Android interceptor because the backend now supports enforcement.
- [ ] **Side note — external:** register release signing fingerprints and debug tokens in Firebase, monitor production verification results, then switch `FIREBASE_APP_CHECK_MODE` from `monitor` to `enforce`.

### 9. Implement push-notification deep links

- [x] Define and emit a versioned notification payload contract.
- [x] Route post, conversation, follow, and other supported types to their intended destinations.
- [x] Validate IDs and provide a safe feed fallback for invalid or stale/deleted targets.
- [x] Route notification taps through current authentication and email-verification state.
- [ ] **Side note — deferred:** execute Android unit/lint checks and device-test foreground, background, killed-process, authenticated, and signed-out notification states when the Android toolchain/device is available.

### 10. Harden image and direct-upload processing

Verified complete 2026-08-29 — the code landed in `b312411` but the boxes were never ticked.

- [x] Enforce byte, MIME/magic, dimension, and total-pixel limits before full decoding
      (`App\Services\ImageSafetyInspector`: streaming byte cap, `getimagesize` header read,
      `finfo` magic check, dimension and total-pixel caps — all before any full decode).
- [x] Cap remote avatar response sizes and redirects (`ImageProcessingService`, via
      `social.images.remote_avatar_max_bytes` / `remote_avatar_max_redirects`).
- [x] Verify committed object hashes and expected content server-side.
- [x] Bind upload commits to a nonce, user/device identity, protocol version, and expiry.
- [x] Test decompression bombs, malformed images, spoofed MIME types, and stale commits
      (`ImageSafetyInspectorTest`, `UploadApiTest` — 10 commit-path cases).

### 11. Reduce capture-service battery impact

- [ ] Measure wake-lock and foreground-service behavior on representative devices.
- [ ] Replace the unbounded wake lock with scoped or timed acquisition where possible.
- [ ] Confirm recovery after process death, reboot, Doze, and permission revocation.
- [ ] Document user-visible battery impact and controls.

### 18. Keep media bytes off the application server

- [x] Authorize the request, then redirect to a presigned object-store URL instead of streaming
      the object through PHP-FPM (`App\Support\Media\MediaDelivery`, used by all four delivery
      controllers). Streaming held a worker for the full duration of a transfer that is almost
      entirely network wait, and because capability URLs are viewer-bound no shared CDN cache
      could deduplicate them between users — so every image was paid for twice in origin
      bandwidth. This is a capacity fix, not a security change: authorization still runs on every
      request.
- [x] Exclude local disks from offloading — they can sign URLs, but the URL points back at this
      same application, so the redirect would cost a round trip and save nothing.
- [x] Never allow public caching of the redirect: its `Location` header is a bearer capability for
      the object. Already-restricted media stays `no-store`.
- [x] Bound the presigned URL's own lifetime (`SOCIAL_MEDIA_OFFLOAD_TTL_SECONDS`, default 120s)
      well below the capability lifetime, since the capability is reauthorized on every use and
      the presigned URL is not.
- [x] Provide `SOCIAL_MEDIA_OFFLOAD_ENABLED=false` to force streaming without a deploy.
- [x] Add tests for the redirect path, restricted-media caching, revocation, and the kill switch.

### 19. Stop the `.env.example` media-disk footgun

- [x] `SOCIAL_PRIVATE_MEDIA_DISK=local` was the shipped default with no sibling keys nearby, so
      copying `.env.example` to a server silently wrote user media to the app server's own disk —
      which works in testing and then loses every upload the next time the server is rebuilt.
      All three disk keys are now listed together with an explicit production warning.

## Phase 3: Build, test, and operations

### 12. Make CI reproduce the local quality gates

- [ ] Backend CI: run formatting check, PHPStan, OpenAPI contract check, tests, Composer audit, and npm audit.
- [ ] Fail if an auto-formatter changes tracked files.
- [ ] Android CI: run unit tests, lint, screenshot verification, debug build, and release/R8 build.
- [ ] Add Android 14-16 coverage for permissions and foreground-service behavior.
- [ ] Add dependency-update automation for Composer, npm, Gradle, and GitHub Actions.

### 13. Resolve dependency audit findings

- [x] Upgrade or replace vulnerable `nanoid`, `postcss`, and `shell-quote` dependency paths
      (all four high-severity advisories cleared by a non-breaking `npm audit fix`).
- [x] Move build-only npm packages to `devDependencies` (`vite`, `laravel-vite-plugin`,
      `@tailwindcss/vite`, `tailwindcss`, `concurrently`). Only `@laravel/passkeys` is a real
      runtime dependency — it is the one package that ends up in the browser bundle. Deploy is
      unaffected: the runbook already runs a plain `npm ci`, which installs dev dependencies.
- [x] Re-run npm dependency/security checks — `npm audit` and `npm audit --omit=dev` both report
      0 vulnerabilities, verified against a clean `rm -rf node_modules && npm ci` plus a
      successful `npm run build`.
- [ ] Re-run Composer and Gradle dependency/security checks.
- [ ] Document accepted exceptions with owner and expiry date.

### 14. Perform a clean Android release qualification

- [ ] Free sufficient build-host storage and rerun Gradle tasks serially.
- [ ] Run unit tests, lint, Roborazzi verification, debug assembly, and signed release bundle generation.
- [ ] Install and smoke-test the minified release build.
- [ ] Verify API, authentication, telemetry, uploads, push notifications, capture, and recovery against staging.
- [ ] Test upgrade from the last distributed application version.

### 15. Validate backend deployment and disaster recovery

- [ ] Deploy to a staging environment matching production services.
- [ ] Verify queue workers, scheduler, object storage, FCM, mail, cache, and database pooling.
- [ ] Exercise backup restore, failed migration rollback, queue recovery, and credential rotation.
- [ ] Add alerts for error rate, queue age, telemetry ingestion, storage failures, and notification delivery.

## Phase 4: Product completeness and documentation

### 16. Resolve deferred or advertised features

- [ ] Either implement Facebook authentication end to end or remove it from production UI and store copy.
- [ ] Review legacy API endpoints and remove them only after confirming no supported client uses them.
- [ ] Ensure notification, privacy, retention, and account-deletion behavior matches product copy.

### 17. Bring launch documentation up to date

- [ ] Replace stale Android readiness statements with verified current results.
- [ ] Create a release checklist with accountable owners and evidence links.
- [ ] Document secrets, key rotation, deployment, rollback, incident response, and store submission.
- [ ] Record any accepted residual risk with owner and review date.

## Final release gate

- [ ] All Phase 1 items are complete.
- [ ] No unresolved critical or high security finding remains without explicit written acceptance.
- [ ] Backend and Android CI are green from clean checkouts.
- [ ] A signed Android release passes staging end-to-end tests.
- [ ] Production migration, rollback, backup restore, and monitoring have been exercised.
- [ ] Google Play declarations, disclosures, privacy policy, and Data Safety answers are approved and consistent.
