<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ConnectedAccountService
{
    /** @return Collection<int, SocialAccount> */
    public function listFor(User $user): Collection
    {
        return $user->socialAccounts()->latest('id')->get();
    }

    /**
     * Idempotent for an already-unlinked provider (matches this API's existing
     * unlike/unfollow/revoke-session convention) — but refuses when it would leave the
     * account with no way to sign in at all: no password set, and this is the only
     * linked provider.
     */
    public function unlink(User $user, string $provider): void
    {
        $account = $user->socialAccounts()->where('provider', $provider)->first();

        if (! $account) {
            return;
        }

        if ($user->password === null && $user->socialAccounts()->count() <= 1) {
            throw ValidationException::withMessages([
                'provider' => __('You must set a password before unlinking your only sign-in method.'),
            ]);
        }

        $account->delete();
    }
}
