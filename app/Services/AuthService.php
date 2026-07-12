<?php

namespace App\Services;

use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\Auth\TwoFactorRequired;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthService
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /** @param  array<string, string>  $data */
    public function register(array $data): IssuedAccessToken
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        // isNewAccount stays at its default (false) here — this endpoint's response
        // never included an `is_new_account` field (unlike social login's, which needs
        // it to distinguish 200 vs 201), and a plain /auth/register call is redundant
        // with that fact anyway. Don't change the wire shape as a side effect of this
        // DTO refactor.
        return new IssuedAccessToken($user, $token);
    }

    /**
     * Matches `login` against email OR username. If the account has two-factor
     * authentication enabled, this stops short of issuing a token and instead returns a
     * short-lived challenge — see {@see twoFactorChallengeResponse()} and
     * {@see completeTwoFactorChallenge()}.
     *
     * @param  array<string, string>  $credentials  Expects 'login' and 'password' keys.
     */
    public function login(array $credentials, string $deviceName = 'mobile'): IssuedAccessToken|TwoFactorRequired
    {
        $user = User::query()
            ->where('email', $credentials['login'])
            ->orWhere('username', $credentials['login'])
            ->first();

        if ($user && $user->password === null) {
            throw ValidationException::withMessages([
                'login' => __('This account signs in with Google, Facebook, or Apple. Continue with one of those, or set a password from your profile first.'),
            ]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->twoFactorChallengeResponse($user);
        }

        $token = $user->createToken($deviceName)->plainTextToken;

        return new IssuedAccessToken($user, $token);
    }

    /** Revokes only the current token — a login on another device stays valid. */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Sets a password on an account that may not have had one before (a social-only
     * sign-up completing profile setup) or is changing an existing one — the caller
     * (SetPasswordRequest) has already confirmed `current_password` when one exists.
     */
    public function setPassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->save();
    }

    /**
     * Issues a short-lived, single-use challenge token in place of a Sanctum token —
     * the stateless equivalent of Fortify's own session-stored "who's mid-login" state,
     * since a Sanctum API client has no PHP session to hold that in. The client is
     * expected to prompt for a TOTP/recovery code and complete the login via
     * {@see completeTwoFactorChallenge()}.
     */
    public function twoFactorChallengeResponse(User $user): TwoFactorRequired
    {
        $challengeToken = Str::random(64);

        Cache::put("two-factor-challenge:{$challengeToken}", $user->id, now()->addMinutes(5));

        return new TwoFactorRequired($challengeToken);
    }

    /**
     * Serialized per challenge token via `Cache::lock` — without it, two concurrent
     * requests for the same `two_factor_token` could both read the still-present cache
     * entry, both pass verification, and both mint a token before either call to
     * `Cache::forget()` lands (a classic check-then-act race on a "single-use" token).
     */
    public function completeTwoFactorChallenge(
        string $challengeToken,
        ?string $code,
        ?string $recoveryCode,
        string $deviceName,
    ): IssuedAccessToken {
        $cacheKey = "two-factor-challenge:{$challengeToken}";
        $lock = Cache::lock("{$cacheKey}:lock", 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'two_factor_token' => __('This two-factor challenge is already being completed. Please try again in a moment.'),
            ]);
        }

        try {
            /** @var int|null $userId */
            $userId = Cache::get($cacheKey);

            if (! $userId) {
                throw ValidationException::withMessages([
                    'two_factor_token' => __('This two-factor challenge has expired or is invalid. Please log in again.'),
                ]);
            }

            $user = User::query()->findOrFail($userId);

            $this->verifyTwoFactorCode($user, $code, $recoveryCode);

            Cache::forget($cacheKey);

            $token = $user->createToken($deviceName)->plainTextToken;

            return new IssuedAccessToken($user, $token);
        } finally {
            $lock->release();
        }
    }

    /**
     * A recovery code is checked first and, if it matches, consumed (replaced with a
     * fresh one) — `$user->replaceRecoveryCode()` itself is a silent no-op for a code
     * that isn't actually present, so membership is checked explicitly first rather
     * than trusting that call alone to signal success.
     *
     * Recovery-code consumption is additionally locked per-user (not just per challenge
     * token): two *different* challenge tokens — e.g. two concurrent login attempts —
     * could otherwise both race to consume the same recovery code, since each holds its
     * own `$user` instance loaded before either write lands. `$user->refresh()` after
     * acquiring the lock picks up whatever the other request already committed.
     */
    private function verifyTwoFactorCode(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($recoveryCode) {
            $lock = Cache::lock("recovery-code-consume:{$user->id}", 10);

            if (! $lock->get()) {
                throw ValidationException::withMessages([
                    'code' => __('Please try again in a moment.'),
                ]);
            }

            try {
                $user->refresh();

                if (! in_array($recoveryCode, $user->recoveryCodes(), true)) {
                    throw ValidationException::withMessages([
                        'code' => __('The provided recovery code is invalid.'),
                    ]);
                }

                $user->replaceRecoveryCode($recoveryCode);
            } finally {
                $lock->release();
            }

            return;
        }

        if (! $code || ! $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }
    }
}
