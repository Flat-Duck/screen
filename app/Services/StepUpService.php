<?php

namespace App\Services;

use App\Mail\AccountConfirmationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Proof-of-ownership for destructive/identity-changing actions (delete account, change
 * email, unlink the last sign-in provider, enable/disable/regenerate 2FA) — a valid
 * bearer token alone is what every *other* authenticated endpoint requires, but a stolen
 * token shouldn't be enough on its own for these. `RequiresCurrentPassword`'s old
 * behavior of silently skipping the check for a passwordless (social-only) account left
 * exactly that gap open; this replaces it with a method chosen by what the account
 * actually has available, never "nothing":
 *
 *  - a password set → the existing `current_password` check (unchanged from before)
 *  - no password, but 2FA enabled → a fresh TOTP code (`two_factor_code`)
 *  - neither → a one-time code mailed to the account's own (already-verified) inbox
 *    (`confirmation_code`), requested first via `POST /v1/account/confirmation-code`
 */
class StepUpService
{
    private const EMAIL_CODE_TTL_MINUTES = 10;

    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /** @return 'password'|'two_factor'|'email_code' */
    public function requiredMethod(User $user): string
    {
        if ($user->password !== null) {
            return 'password';
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return 'two_factor';
        }

        return 'email_code';
    }

    /**
     * Only meaningful when {@see requiredMethod()} would return 'email_code' — mails a
     * fresh 6-digit code to the account's own email, valid for 10 minutes, single-use.
     */
    public function sendEmailCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->emailCodeCacheKey($user), Hash::make($code), now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES));

        Mail::to($user->email)->send(new AccountConfirmationCodeMail($code));
    }

    /**
     * @param  array<string, mixed>  $input  Expects whichever of 'current_password',
     *                                       'two_factor_code', 'confirmation_code' is
     *                                       relevant per {@see requiredMethod()}.
     *
     * @throws ValidationException
     */
    public function verify(User $user, array $input): void
    {
        match ($this->requiredMethod($user)) {
            'password' => $this->verifyPassword($user, $input),
            'two_factor' => $this->verifyTwoFactor($user, $input),
            'email_code' => $this->verifyEmailCode($user, $input),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function verifyPassword(User $user, array $input): void
    {
        $password = $input['current_password'] ?? null;

        if (! is_string($password) || $password === '' || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ]);
        }
    }

    /** @param  array<string, mixed>  $input */
    private function verifyTwoFactor(User $user, array $input): void
    {
        $code = $input['two_factor_code'] ?? null;

        if (
            ! is_string($code) || $code === ''
            || ! $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)
        ) {
            throw ValidationException::withMessages([
                'two_factor_code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }
    }

    /**
     * Locked per-user around the whole check-then-consume sequence — without it, two
     * concurrent requests presenting the same code could both pass `Hash::check()`
     * before either `Cache::forget()` call lands, defeating "single-use" the same way
     * the 2FA challenge/recovery-code races did (see AuthService).
     *
     * @param  array<string, mixed>  $input
     */
    private function verifyEmailCode(User $user, array $input): void
    {
        $code = $input['confirmation_code'] ?? null;
        $key = $this->emailCodeCacheKey($user);
        $lock = Cache::lock("{$key}:lock", 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'confirmation_code' => __('Please try again in a moment.'),
            ]);
        }

        try {
            $hashed = Cache::get($key);

            if (! is_string($code) || $code === '' || ! is_string($hashed) || ! Hash::check($code, $hashed)) {
                throw ValidationException::withMessages([
                    'confirmation_code' => __('The provided confirmation code is invalid or has expired.'),
                ]);
            }

            // Single-use: consumed on successful verification, same as a 2FA recovery code.
            Cache::forget($key);
        } finally {
            $lock->release();
        }
    }

    private function emailCodeCacheKey(User $user): string
    {
        return "step-up-email-code:{$user->id}";
    }
}
