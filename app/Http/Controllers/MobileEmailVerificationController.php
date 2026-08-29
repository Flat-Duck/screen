<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Response;

final class MobileEmailVerificationController extends Controller
{
    public function __invoke(User $user, string $hash): Response
    {
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->view('auth.mobile-email-verified');
    }
}
