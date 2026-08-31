<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Requests\ForgotPasswordApiRequest;
use App\Http\Requests\ResetPasswordApiRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final class AccountRecoveryController extends Controller
{
    public function forgotPassword(ForgotPasswordApiRequest $request): JsonResponse
    {
        $email = $request->string('email')->lower()->toString();
        $user = User::query()->where('email', $email)->first();

        // A social-only account has no password to recover. Deliberately return the same body as
        // every other address; the owner can continue with its provider and add a password later.
        if ($user?->password !== null) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json([
            'message' => __('If an eligible account exists for that email, a reset link has been sent.'),
        ], 202);
    }

    public function resetPassword(ResetPasswordApiRequest $request, ResetUserPassword $resetUserPassword): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password', 'password_confirmation', 'token']);
        $status = Password::reset(
            $credentials,
            function (User $user, string $password) use ($request, $resetUserPassword): void {
                $resetUserPassword->reset($user, [
                    'password' => $password,
                    'password_confirmation' => (string) $request->input('password_confirmation'),
                ]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }

        return response()->json(['message' => __('Your password has been reset. Sign in with the new password.')]);
    }
}
