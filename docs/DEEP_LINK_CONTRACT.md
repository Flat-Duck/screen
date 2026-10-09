# Akukas HTTPS link contract

This is the source-of-truth URL contract for the Android client and Laravel deployment.

## Canonical origin and routes

The canonical public origin is https://akukas.ly, configured on the backend with
AKUKAS_CANONICAL_URL. The Android authLinkHost Gradle property is akukas.ly.

| URL | Destination | Resolution |
| --- | --- | --- |
| https://akukas.ly/posts/{id} | Existing post detail screen | {id} is a positive decimal 64-bit database ID. Android loads GET /api/v1/posts/{id} with the current user session. The API applies visibility, block, moderation, and deletion checks and returns 404 when unavailable. |
| https://akukas.ly/profile/{id} | Existing user profile screen | {id} is a positive decimal 64-bit database ID. Android loads existing user/profile APIs; their privacy and block checks remain authoritative. |
| https://akukas.ly/invite/{token} | Existing invite gate, then registration/login | New tokens are 64 lowercase hexadecimal characters (256 random bits). The database stores only SHA-256 hashes. |
| https://akukas.ly/invite/{code} | Existing invite gate, then registration/login | Backward-compatible legacy invite codes remain accepted when they are 1–32 ASCII letters or digits. |

Only HTTPS, the exact akukas.ly host, exact two-segment paths, and the shapes above are accepted
by the Android parser. Query strings, fragments, alternate hosts/schemes, ports, user info, and
unsupported paths are rejected. Resource URLs contain no bearer credentials or private profile
data.

## Invitation lifecycle

1. An authenticated, verified account requests POST /api/v1/me/invite-link. The response is a
   canonical /invite/{token} URL. Legacy codes remain visible/copyable in the existing UI.
2. Installed-app routing opens the invite gate. The client sends invite_token to the existing
   device-authenticated POST /api/v1/auth/invites/reserve; legacy manual entry still sends
   invite_code. The backend resolves either source to the existing inviter code and issues the
   same short-lived invite ticket used by register and social registration.
3. The invite ticket continues through registration. Existing code rules, registration gating,
   inviter attribution, unique per-invitee redemption, and point maturity remain unchanged.
4. Without the app, the /invite/{token} page links to the configured APP_PLAY_URL and passes
   invite_token={token} in Play's referrer query parameter. Legacy links pass invite_code={code}
   instead. The app reads this once through Play Install Referrer on first launch, validates the
   recovered value with the same reservation API, and retains the resulting ticket through
   registration.

Play Install Referrer transports only the referral string supplied on the Play listing URL. It
does not defer arbitrary URLs. Post and profile fallbacks show the configured Play listing, but
their original destination is not promised after installation.

## App Links and fallback

The Android manifest has separate autoVerify filters for /invite/, /posts/, /profile/, and the
existing mobile password-reset path. /.well-known/assetlinks.json is public JSON for package
ly.akukas.akukasapp; configure ANDROID_APP_LINK_SHA256_FINGERPRINTS as a comma-separated list
including the Play App Signing certificate fingerprint and any release distribution signer. The
current default is the repository's previously verified upload-key fingerprint, which alone is
not sufficient when Play App Signing re-signs delivered APKs.

Post/profile web fallbacks do not disclose resource information. They give a clear Play Store link
and leave visibility enforcement to the authenticated API. Invite fallback pages preserve the
invite only through the documented Play Install Referrer flow. Set APP_PLAY_URL to the real
Akukas Play listing and keep its package ID aligned with ANDROID_PACKAGE_NAME and Android's
applicationId.

Because invitation tokens are path segments, configure the web server/CDN access log to redact
the 64-character segment on /invite/{token}. Laravel Telescope drops those request entries and
hides invite fields in captured request parameters; infrastructure access logs are outside Laravel.
