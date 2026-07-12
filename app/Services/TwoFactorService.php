<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * A thin wrapper around Laravel Fortify's own 2FA action classes (guard-agnostic — they
 * just mutate a `$user` model, nothing session/web-guard specific), used instead of
 * Fortify's own routes/controllers since those are session-only and this app's mobile
 * API is Sanctum-token based.
 */
class TwoFactorService
{
    public function __construct(
        private readonly EnableTwoFactorAuthentication $enableAction,
        private readonly ConfirmTwoFactorAuthentication $confirmAction,
        private readonly DisableTwoFactorAuthentication $disableAction,
        private readonly GenerateNewRecoveryCodes $regenerateRecoveryCodesAction,
    ) {}

    /**
     * Generates (or, if a setup was started but never confirmed, re-fetches the same
     * in-progress) secret + recovery codes, returning everything an authenticator app
     * needs in this one response — see the routes/api_v1.php doc comment for why this
     * differs from Fortify's own multi-request web flow.
     *
     * @return array{qr_code_svg: string, qr_code_url: string, recovery_codes: array<int, string>}
     */
    public function enable(User $user): array
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor' => __('Two-factor authentication is already enabled. Disable it first to set up again.'),
            ]);
        }

        ($this->enableAction)($user);

        return [
            'qr_code_svg' => $user->twoFactorQrCodeSvg(),
            'qr_code_url' => $user->twoFactorQrCodeUrl(),
            'recovery_codes' => $user->recoveryCodes(),
        ];
    }

    /** @throws ValidationException if $code doesn't match the pending secret. */
    public function confirm(User $user, string $code): void
    {
        ($this->confirmAction)($user, $code);
    }

    public function disable(User $user): void
    {
        ($this->disableAction)($user);
    }

    /** @return array<int, string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        ($this->regenerateRecoveryCodesAction)($user);

        return $user->recoveryCodes();
    }
}
