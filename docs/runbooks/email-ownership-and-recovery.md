# Email ownership and account recovery

## Policy

Password registration creates a normal user session so the client can resend verification, correct
a mistyped address, delete the account, or log out. Until ownership is proven, the
`verified.email` API middleware blocks every social/product route (feeds, profiles, posts,
messaging, uploads, settings, and social graph actions) with `403` and
`code=email_not_verified`.

The intentionally unverified-safe API surface is limited to:

- email-verification status and resend;
- logout and password/account recovery;
- changing a mistyped email and deleting the account;
- the existing step-up confirmation endpoint needed by passwordless accounts.

Google and Facebook accounts are immediately verified only when the backend has independently
validated the provider token and the provider guarantees the returned email is verified. An
unverified provider email cannot auto-link to an existing account. A social-only account receives
the same generic forgot-password response as every address, but no reset mail: it must continue
with its provider and may add a password after signing in.

## Ownership and recovery flow

1. Password registration queues a signed, one-hour verification link after the user transaction
   commits. The auth response returns `next_action=verify_email`.
2. The Android app persists that gate with the encrypted user session. Restarts return to the
   verification screen instead of opening the feed.
3. The HTTPS email link verifies the address server-side and offers a safe app handoff. Android
   polls the authenticated status endpoint before allowing onboarding to continue.
4. Forgot-password always returns `202` with the same text. Eligible password accounts receive a
   queued reset notification; missing and social-only accounts do not reveal their state.
5. A reset link opens the native reset screen through a verified HTTPS App Link, with a browser
   landing-page/custom-scheme fallback. A successful reset also verifies the email and revokes all
   existing user sessions and tokens. This lets the actual mailbox owner recover an address that
   somebody else attempted to pre-register.

Verification resend is limited to three requests per user per minute. Forgot/reset are limited to
five requests per normalized-email-and-IP key per minute. The public signed-link endpoints have
additional IP limits. Mail notifications implement `ShouldQueue` and are dispatched only after a
successful database commit, so the production queue worker is required.

## Deployment checks

- `APP_URL` must be the public HTTPS origin used in mail links.
- Android `authLinkHost` in `gradle.properties` must match that origin's host.
- Publish `/.well-known/assetlinks.json` for package `ly.akukas.akukasapp` with the production app
  signing certificate fingerprint. This is an external/manual release task; without it, the secure
  browser fallback still works, but Android will not verify direct reset-link routing.
- Verify the production mail sender/domain and queue worker, then exercise registration,
  verification, resend, unknown-address recovery, social-only recovery, reset, expired token, and
  post-reset session revocation against staging.

Never log verification URLs or reset tokens. Treat a reset link as a credential until it expires or
is consumed.
