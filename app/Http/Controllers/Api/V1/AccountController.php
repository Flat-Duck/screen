<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ChangeEmailRequest;
use App\Http\Requests\DeleteAccountRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Services\EmailChangeService;
use App\Services\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $account,
        private readonly EmailChangeService $emailChange,
        private readonly StepUpService $stepUp,
    ) {}

    /**
     * Requests the email code half of {@see StepUpService} — only meaningful (and only
     * sent) for an account with neither a password nor 2FA, since that's the only case
     * StepUpService::verify() will ever actually check it against.
     */
    public function sendConfirmationCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->stepUp->requiredMethod($user) !== 'email_code') {
            throw ValidationException::withMessages([
                'confirmation_code' => __('This account confirms sensitive actions with a password or two-factor code instead.'),
            ]);
        }

        $this->stepUp->sendEmailCode($user);

        return response()->json(null, 204);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->account->deleteAccount($user);

        return response()->json(null, 204);
    }

    /**
     * Only *requests* the change — the live `email` column isn't touched until the
     * signed link mailed to the new address is clicked (see EmailChangeService).
     */
    public function changeEmail(ChangeEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->emailChange->requestChange($user, $request->string('email')->toString());

        return response()->json(['pending_email' => $user->pending_email]);
    }
}
