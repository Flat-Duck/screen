<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ConfirmTwoFactorRequest;
use App\Http\Requests\DisableTwoFactorRequest;
use App\Http\Requests\EnableTwoFactorRequest;
use App\Http\Requests\RegenerateRecoveryCodesRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['enabled' => $user->hasEnabledTwoFactorAuthentication()]);
    }

    public function store(EnableTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->twoFactor->enable($user));
    }

    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->confirm($user, $request->string('code')->toString());

        return response()->json(['enabled' => true]);
    }

    public function destroy(DisableTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->disable($user);

        return response()->json(null, 204);
    }

    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)]);
    }
}
