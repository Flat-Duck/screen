<?php

namespace App\Http\Responses;

use App\Http\Resources\UserResource;
use App\Services\Auth\IssuedAccessToken;
use App\Services\Auth\TwoFactorRequired;
use App\Services\InterestPreferenceService;
use Illuminate\Http\JsonResponse;

final class AuthResponseFactory
{
    public function __construct(private readonly InterestPreferenceService $interests) {}

    public function make(
        IssuedAccessToken|TwoFactorRequired $result,
        int $successStatus = 200,
        bool $includeIsNewAccount = false,
    ): JsonResponse {
        if ($result instanceof TwoFactorRequired) {
            return response()->json([
                'requires_two_factor' => true,
                'two_factor_token' => $result->twoFactorToken,
            ]);
        }

        $onboarding = $this->interests->status($result->user);

        return response()->json([
            'user' => new UserResource($result->user->loadCount(['posts', 'followers', 'following'])),
            'token' => $result->token,
            'session_id' => $result->session->uuid,
            ...($includeIsNewAccount ? ['is_new_account' => $result->isNewAccount] : []),
            'profile_completion' => $result->user->profileCompletionStatus(),
            'onboarding' => ['interests' => $onboarding],
            'next_action' => $onboarding['needs_selection'] ? 'select_interests' : 'for_you',
        ], $successStatus);
    }
}
