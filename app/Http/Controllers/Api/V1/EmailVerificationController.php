<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\EmailVerificationCodeService;
use App\Services\InterestPreferenceService;
use App\Services\InvitationRewardPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly InterestPreferenceService $interests,
        private readonly EmailVerificationCodeService $codes,
        private readonly InvitationRewardPresenter $invitationRewards,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $onboarding = $this->interests->status($user);
        $invitationReward = $user->hasVerifiedEmail() ? $this->invitationRewards->forInvitee($user) : null;

        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
            'next_action' => match (true) {
                ! $user->hasVerifiedEmail() => 'verify_email',
                $onboarding['needs_selection'] => 'select_interests',
                default => 'for_you',
            },
            'invitation_reward' => $invitationReward,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $this->codes->send($user);
        }

        return response()->json([
            'message' => __('If verification is still required, a new email has been sent.'),
        ], 202);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->codes->verify($user, $validated['code']);

        return response()->json(['verified' => true]);
    }
}
