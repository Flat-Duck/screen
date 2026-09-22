<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `GET /invite/{code}` — the web fallback for someone opening an invite link without the app
 * installed. The installed-app case never reaches this: the same URL is registered as a verified
 * Android App Link (`pathPrefix: /invite/`), which intercepts it client-side before it would ever
 * hit a browser. The code itself lives in the URL path, so nothing here needs to validate or
 * resolve it — that happens once the app (or this page's Play Store link, via the Play Install
 * Referrer API) gets it to `POST /v1/auth/invites/reserve`.
 */
class InviteLandingController extends Controller
{
    public function __invoke(Request $request, string $code): View
    {
        $packageName = config('social.android_package_name');
        $playStoreUrl = sprintf(
            'https://play.google.com/store/apps/details?id=%s&referrer=%s',
            $packageName,
            urlencode('invite_code='.$code),
        );

        return view('invite.show', [
            'code' => $code,
            'playStoreUrl' => $playStoreUrl,
        ]);
    }
}
