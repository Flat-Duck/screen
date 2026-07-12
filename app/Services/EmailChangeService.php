<?php

namespace App\Services;

use App\Mail\ChangeEmailVerificationMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * `pending_email` doesn't touch the live `email` column until the link is clicked — the
 * confirmation is a **web** route (routes/web.php), not `/api/v1`, since it's opened from
 * an email client, not called by the Android app itself (see AccountController::changeEmail
 * for the /v1 half that only *requests* the change).
 */
class EmailChangeService
{
    public function requestChange(User $user, string $newEmail): void
    {
        $user->pending_email = $newEmail;
        $user->save();

        $url = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($newEmail)],
        );

        Mail::to($newEmail)->send(new ChangeEmailVerificationMail($url));
    }

    /**
     * `$hash` binds the signed link to the exact pending email it was issued for — if the
     * user requests a second change before confirming the first, the first link's hash no
     * longer matches `pending_email` and this rejects it instead of silently confirming
     * whichever email happens to be pending by the time it's clicked.
     */
    public function confirm(User $user, string $hash): void
    {
        if ($user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
            throw ValidationException::withMessages([
                'email' => __('This email confirmation link is invalid or has expired.'),
            ]);
        }

        $user->email = $user->pending_email;
        // $user->email_verified_at is cast to the mutable Illuminate\Support\Carbon, not
        // the app-wide default CarbonImmutable (AppServiceProvider::boot()) —
        // Carbon::now() matches the cast's declared type, now() doesn't.
        $user->email_verified_at = Carbon::now();
        $user->pending_email = null;
        $user->save();
    }
}
