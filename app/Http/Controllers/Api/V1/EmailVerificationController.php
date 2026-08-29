<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\InterestPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(private readonly InterestPreferenceService $interests) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $onboarding = $this->interests->status($user);

        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
            'next_action' => match (true) {
                ! $user->hasVerifiedEmail() => 'verify_email',
                $onboarding['needs_selection'] => 'select_interests',
                default => 'for_you',
            },
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => __('If verification is still required, a new email has been sent.'),
        ], 202);
    }
}
