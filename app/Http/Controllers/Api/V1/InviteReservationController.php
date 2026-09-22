<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ReserveInviteRequest;
use App\Services\InviteCodeService;
use Illuminate\Http\JsonResponse;

class InviteReservationController extends Controller
{
    public function __construct(private readonly InviteCodeService $inviteCodes) {}

    /** `GET /v1/auth/invite-config` — device-token authenticated, called before the invite-gate
     * screen decides whether to show itself at all (see AndroidClient's Landing screen, which
     * fires this the instant it's created rather than waiting for a "Sign up" tap). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'required' => $this->inviteCodes->isRequired(),
        ]]);
    }

    /** `POST /v1/auth/invites/reserve` — validates the code exactly like registration eventually
     * will, and mints a short-lived ticket the client carries into `invite_ticket` on
     * register/social-login instead of re-sending the raw code. See InviteReservation's kdoc for
     * why this never introduces scarcity the underlying code didn't already have. */
    public function reserve(ReserveInviteRequest $request): JsonResponse
    {
        $reservation = $this->inviteCodes->reserve($request->string('invite_code')->toString());

        return response()->json(['data' => [
            'ticket' => $reservation->ticket,
            'expires_at' => $reservation->expires_at->toISOString(),
        ]], 201);
    }
}
