<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * `GET /invite/{code}` — the web fallback for someone opening an invite link without the app
 * installed. The URL may contain a legacy human-readable code or a 256-bit opaque token. Tokens
 * are resolved only by the authenticated-device reservation endpoint; this page never discloses
 * inviter identity or validates tokens itself.
 */
class InviteLandingController extends Controller
{
    public function __invoke(string $code): View
    {
        $isToken = preg_match('/\A[a-f0-9]{64}\z/', $code) === 1;
        $referrer = $isToken ? 'invite_token='.$code : 'invite_code='.$code;
        $playStoreUrl = config('social.android_play_store_url');
        $separator = str_contains($playStoreUrl, '?') ? '&' : '?';
        $playStoreUrl .= $separator.'referrer='.urlencode($referrer);

        return view('invite.show', [
            'invitation' => $isToken ? null : $code,
            'playStoreUrl' => $playStoreUrl,
        ]);
    }
}
