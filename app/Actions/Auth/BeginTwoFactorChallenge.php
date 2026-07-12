<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorRequired;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BeginTwoFactorChallenge
{
    public function __invoke(User $user): TwoFactorRequired
    {
        $challengeToken = Str::random(64);
        Cache::put("two-factor-challenge:{$challengeToken}", $user->id, now()->addMinutes(5));

        return new TwoFactorRequired($challengeToken);
    }
}
