<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class EmailVerificationCodeService
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function send(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->cacheKey($user), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        $user->notify(new VerifyEmailNotification($code));
    }

    public function verify(User $user, string $code): void
    {
        $key = $this->cacheKey($user);
        $lock = Cache::lock("{$key}:lock", 10);

        if (! $lock->get()) {
            throw $this->invalidCode();
        }

        try {
            if ($user->hasVerifiedEmail()) {
                Cache::forget($key);

                return;
            }

            $entry = Cache::get($key);
            if (! is_array($entry) || ! is_string($entry['hash'] ?? null)) {
                throw $this->invalidCode();
            }

            $attempts = (int) ($entry['attempts'] ?? 0) + 1;
            if (! Hash::check($code, $entry['hash'])) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    Cache::forget($key);
                } else {
                    $entry['attempts'] = $attempts;
                    Cache::put($key, $entry, now()->addMinutes(self::CODE_TTL_MINUTES));
                }

                throw $this->invalidCode();
            }

            Cache::forget($key);

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(User $user): string
    {
        return "email-verification-code:{$user->getKey()}";
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => __('The verification code is invalid or has expired.'),
        ]);
    }
}
