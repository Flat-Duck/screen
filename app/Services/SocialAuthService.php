<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAuth\SocialUserPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAuthService
{
    public function __construct(private readonly ImageProcessingService $images) {}

    /**
     * Finds the user behind a verified provider identity, creating one on first sight —
     * "Continue with Google/Facebook/Apple" is one idempotent action per provider
     * identity, never a separate register-then-login pair. A verified email matching an
     * existing account links this provider to it instead of creating a duplicate.
     *
     * @return array{user: User, token: string, is_new_account: bool}
     */
    public function loginOrRegister(SocialUserPayload $payload, string $deviceName): array
    {
        $isNewAccount = false;

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

            if (! $user) {
                // Email taken but the provider didn't vouch for it (emailVerified=false)
                // — refuse rather than either creating a duplicate (impossible, `email`
                // is unique) or silently linking an unproven identity to someone else's
                // account.
                if (User::query()->where('email', $payload->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => __('An account already exists with this email. Log in with your password first, then connect this provider from your profile.'),
                    ]);
                }

                $isNewAccount = true;
                $user = $this->createUser($payload);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $payload->provider,
                'provider_user_id' => $payload->providerUserId,
                'avatar_url' => $payload->avatarUrl,
            ]);

            return $user;
        });

        $token = $user->createToken($deviceName)->plainTextToken;

        return ['user' => $user, 'token' => $token, 'is_new_account' => $isNewAccount];
    }

    /**
     * `username` and `password` are deliberately left null — social sign-in never
     * collects either, and both are surfaced via User::profileCompletionStatus() for
     * the client to prompt for later instead of blocking account creation on them now.
     */
    private function createUser(SocialUserPayload $payload): User
    {
        $user = User::create([
            'name' => $payload->name ?: Str::before($payload->email, '@'),
            'email' => $payload->email,
            'username' => null,
            'password' => null,
        ]);

        $dirty = false;

        if ($payload->emailVerified) {
            // $user->email_verified_at is cast to the mutable Illuminate\Support\Carbon,
            // not the app-wide default CarbonImmutable (AppServiceProvider::boot()) —
            // Carbon::now() matches the cast's declared type, now() doesn't.
            $user->email_verified_at = Carbon::now();
            $dirty = true;
        }

        if ($payload->avatarUrl) {
            $stored = $this->images->storeFromUrl($payload->avatarUrl, "avatars/{$user->id}");

            if ($stored) {
                $user->avatar_path = $stored['path'];
                $dirty = true;
            }
        }

        if ($dirty) {
            $user->save();
        }

        return $user;
    }
}
