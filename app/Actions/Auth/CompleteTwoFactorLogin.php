<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class CompleteTwoFactorLogin
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $twoFactor,
        private readonly IssueAccessToken $issueToken,
    ) {}

    public function __invoke(
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
            $this->verifyCode($user, $code, $recoveryCode);
            Cache::forget($cacheKey);

            return ($this->issueToken)($user, $deviceName);
        } finally {
            $lock->release();
        }
    }

    private function verifyCode(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($recoveryCode) {
            $lock = Cache::lock("recovery-code-consume:{$user->id}", 10);

            if (! $lock->get()) {
                throw ValidationException::withMessages(['code' => __('Please try again in a moment.')]);
            }

            try {
                $user->refresh();

                if (! in_array($recoveryCode, $user->recoveryCodes(), true)) {
                    throw ValidationException::withMessages(['code' => __('The provided recovery code is invalid.')]);
                }

                $user->replaceRecoveryCode($recoveryCode);
            } finally {
                $lock->release();
            }

            return;
        }

        if (! $code || ! $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
        }
    }
}
