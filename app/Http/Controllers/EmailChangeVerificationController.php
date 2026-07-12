<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailChangeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Opened from an email client (see EmailChangeService::requestChange()'s mailed link),
 * never from the Android app itself — that's why this is a **web** route rather than
 * under /api/v1, and why it renders an HTML page instead of returning JSON. The `signed`
 * middleware alone proves the link wasn't tampered with; EmailChangeService::confirm()'s
 * hash check on top of that guards against a *stale but still-validly-signed* link (see
 * its own doc comment).
 */
class EmailChangeVerificationController extends Controller
{
    public function __construct(private readonly EmailChangeService $emailChange) {}

    public function verify(Request $request, User $user): View
    {
        try {
            $this->emailChange->confirm($user, $request->string('hash')->toString());
        } catch (ValidationException) {
            return view('email-change-result', ['success' => false]);
        }

        return view('email-change-result', ['success' => true]);
    }
}
