<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthService
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /**
     * @param  array<string, string>  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Matches `login` against email OR username. If the account has two-factor
     * authentication enabled, this stops short of issuing a token and instead returns a
     * short-lived challenge — see {@see twoFactorChallengeResponse()} and
     * {@see completeTwoFactorChallenge()}.
     *
     * @param  array<string, string>  $credentials  Expects 'login' and 'password' keys.
     * @return array{user: User, token: string}|array{requires_two_factor: true, two_factor_token: string}
     */
    public function login(array $credentials, string $deviceName = 'mobile'): array
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

        return ['user' => $user, 'token' => $token];
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
     *
     * @return array{requires_two_factor: true, two_factor_token: string}
     */
    public function twoFactorChallengeResponse(User $user): array
    {
        $challengeToken = Str::random(64);

        Cache::put("two-factor-challenge:{$challengeToken}", $user->id, now()->addMinutes(5));

        return ['requires_two_factor' => true, 'two_factor_token' => $challengeToken];
    }

    /**
     * @return array{user: User, token: string}
     */
    public function completeTwoFactorChallenge(
        string $challengeToken,
        ?string $code,
        ?string $recoveryCode,
        string $deviceName,
    ): array {
        $cacheKey = "two-factor-challenge:{$challengeToken}";

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

        return ['user' => $user, 'token' => $token];
    }

    /**
     * A recovery code is checked first and, if it matches, consumed (replaced with a
     * fresh one) — `$user->replaceRecoveryCode()` itself is a silent no-op for a code
     * that isn't actually present, so membership is checked explicitly first rather
     * than trusting that call alone to signal success.
     */
    private function verifyTwoFactorCode(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($recoveryCode) {
            if (! in_array($recoveryCode, $user->recoveryCodes(), true)) {
                throw ValidationException::withMessages([
                    'code' => __('The provided recovery code is invalid.'),
                ]);
            }

            $user->replaceRecoveryCode($recoveryCode);

            return;
        }

        if (! $code || ! $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }
    }
}
