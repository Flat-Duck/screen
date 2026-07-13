<?php

namespace App\Actions\Auth;

use App\Data\Auth\DeviceSessionContext;
use App\Enums\LoginMethod;
use App\Jobs\ImportSocialAvatar;
use App\Models\Device;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\Auth\TwoFactorRequired;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompleteSocialLogin
{
    public function __construct(
        private readonly StartDeviceSession $startSession,
        private readonly BeginTwoFactorChallenge $beginTwoFactor,
    ) {}

    public function __invoke(Device $device, SocialUserPayload $payload, DeviceSessionContext $context): IssuedAccessToken|TwoFactorRequired
    {
        $isNewAccount = false;

        try {
            $user = DB::transaction(function () use ($payload, &$isNewAccount): User {
                $identity = SocialAccount::query()
                    ->where('provider', $payload->provider)
                    ->where('provider_user_id', $payload->providerUserId)
                    ->first();

                if ($identity) {
                    return $identity->user;
                }

                $user = $payload->emailVerified
                    ? User::query()->where('email', $payload->email)->first()
                    : null;

                if (! $user && User::query()->where('email', $payload->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => __('An account already exists with this email. Log in with your password first, then connect this provider from your profile.'),
                    ]);
                }

                if (! $user) {
                    $isNewAccount = true;
                    $user = User::create([
                        'name' => $payload->name ?: Str::before($payload->email, '@'),
                        'email' => $payload->email,
                        'username' => null,
                        'password' => null,
                        'email_verified_at' => $payload->emailVerified ? Carbon::now() : null,
                    ]);
                }

                SocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $payload->provider,
                    'provider_user_id' => $payload->providerUserId,
                    'avatar_url' => $payload->avatarUrl,
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $identity = SocialAccount::query()
                ->where('provider', $payload->provider)
                ->where('provider_user_id', $payload->providerUserId)
                ->first();

            if ($identity) {
                $user = $identity->user;
                $isNewAccount = false;
            } elseif ($payload->emailVerified && ($user = User::query()->where('email', $payload->email)->first())) {
                $identity = SocialAccount::firstOrCreate(
                    ['provider' => $payload->provider, 'provider_user_id' => $payload->providerUserId],
                    ['user_id' => $user->id, 'avatar_url' => $payload->avatarUrl],
                );
                $user = $identity->user;
                $isNewAccount = false;
            } else {
                throw $exception;
            }
        }

        if ($isNewAccount && $payload->avatarUrl !== null) {
            ImportSocialAvatar::dispatch($user->id, $payload->avatarUrl)->afterCommit();
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return ($this->beginTwoFactor)($user, $device, LoginMethod::from($payload->provider), $context);
        }

        return ($this->startSession)(
            $user,
            $device,
            LoginMethod::from($payload->provider),
            $context,
            isNewAccount: $isNewAccount,
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
