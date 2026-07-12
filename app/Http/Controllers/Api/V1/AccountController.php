<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ChangeEmailRequest;
use App\Http\Requests\DeleteAccountRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Services\EmailChangeService;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $account,
        private readonly EmailChangeService $emailChange,
    ) {}

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
