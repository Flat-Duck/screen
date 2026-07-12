<?php

namespace App\Services;

use App\Mail\ChangeEmailVerificationMail;
use App\Mail\EmailChangedNotificationMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    /**
     * ChangeEmailRequest's `unique:users,pending_email` rule already catches the common
     * case, but doesn't close the window between validation passing and this save
     * committing — two concurrent requests for the same address can both pass
     * validation. The DB's own unique index (see the pending_email migration) is the
     * real backstop; this just turns that constraint violation into the same clean 422
     * instead of an uncaught 500.
     */
    public function requestChange(User $user, string $newEmail): void
    {
        $user->pending_email = $newEmail;

        try {
            $user->save();
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => __('The email has already been taken.'),
                ]);
            }

            throw $e;
        }

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
     *
     * @throws ValidationException if the link is stale/tampered, or (rare) if someone else
     *                             has since taken this exact email as their live address —
     *                             `pending_email`'s own uniqueness only ever guarded against
     *                             two *pending* requests colliding, not a pending request
     *                             losing a race against a brand-new registration for the
     *                             same address freed up by a third party's own email change
     *                             in between. The `email` column's unique index is the real
     *                             backstop for that; this just turns the violation into a
     *                             clean error instead of an uncaught 500.
     */
    public function confirm(User $user, string $hash): void
    {
        if ($user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
            throw ValidationException::withMessages([
                'email' => __('This email confirmation link is invalid or has expired.'),
            ]);
        }

        $oldEmail = $user->email;
        $newEmail = $user->pending_email;

        // The email mutation and token revocation are coordinated as one durable step:
        // either both land or neither does, rather than a save that succeeds followed
        // by a revoke that might not (leaving the account's email changed but every
        // stolen/compromised session still valid). The notification is dispatched only
        // *after* this transaction returns — i.e. only after it's actually committed —
        // never from inside it, so the old owner is never told about a change that got
        // rolled back.
        try {
            DB::transaction(function () use ($user, $newEmail): void {
                $user->email = $newEmail;
                // $user->email_verified_at is cast to the mutable Illuminate\Support\Carbon,
                // not the app-wide default CarbonImmutable (AppServiceProvider::boot()) —
                // Carbon::now() matches the cast's declared type, now() doesn't.
                $user->email_verified_at = Carbon::now();
                $user->pending_email = null;
                $user->save();

                // Revoke every session, not just "other" ones — unlike a password change
                // (done from an already-authenticated request, where "keep this session"
                // is meaningful), this confirmation happens from a signed web link with
                // no session of its own to exempt. A token that changed the account's
                // email is exactly the kind of thing worth forcing a fresh login on
                // every device for.
                $user->tokens()->delete();
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => __('This email address was taken by another account in the meantime. Please request the change again.'),
                ]);
            }

            throw $e;
        }

        Mail::to($oldEmail)->send(new EmailChangedNotificationMail($newEmail));
    }

    /**
     * `getCode()` returns the driver's SQLSTATE, not a fixed integer, so it has to be
     * compared as a string across drivers: MySQL/SQLite both report a unique violation
     * as the general '23000' integrity-constraint-violation class, but Postgres (this
     * app's actual driver — see config/database.php) reports the more specific
     * '23505' (unique_violation) instead. Checking only '23000' looked correct against
     * the SQLite-backed test suite but silently never matched in the real Postgres
     * environment, letting the violation escape as an uncaught 500.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
