# Firebase App Check rollout

The Android app sends `X-Firebase-AppCheck` to the Laravel API. Laravel verifies write requests
against Firebase's rotating JWKS, the numeric project number, and the allow-listed Firebase App
IDs. Existing user/device authorization remains mandatory; App Check is an additional signal, not
an identity mechanism.

1. Register the release Android app and SHA-256 signing fingerprint with the Play Integrity
   provider. Register local/CI debug tokens separately and never commit them.
2. Set `FIREBASE_PROJECT_NUMBER` and `FIREBASE_APP_CHECK_ALLOWED_APP_IDS`, deploy with
   `FIREBASE_APP_CHECK_MODE=monitor`, and aggregate the structured
   `Firebase App Check verification` log by `result`.
3. Investigate missing/invalid traffic. Once supported clients are consistently valid, change the
   mode to `enforce` and rebuild the Laravel configuration cache.
4. Missing or invalid tokens receive `401 APP_CHECK_REQUIRED`. If fresh JWKS retrieval fails,
   cached keys are used for up to 24 hours; with no usable keys, writes fail closed with
   `503 APP_CHECK_UNAVAILABLE` so clients can retry without treating payloads as invalid.
5. Roll back enforcement by returning to `monitor`; do not remove the Android interceptor.

Firebase recommends caching the public key set for up to six hours. Debug tokens grant backend
access from unattested devices and must be managed as secrets in the Firebase console.
