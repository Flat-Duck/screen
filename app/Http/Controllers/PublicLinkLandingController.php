<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Generic browser fallback that never exposes protected resource data. */
class PublicLinkLandingController extends Controller
{
    public function post(int $id): View
    {
        abort_unless($id > 0, 404);

        return view('public-link.show', [
            'playStoreUrl' => config('social.android_play_store_url'),
            'destination' => 'post',
        ]);
    }

    public function user(int $id): View
    {
        abort_unless($id > 0, 404);

        return view('public-link.show', [
            'playStoreUrl' => config('social.android_play_store_url'),
            'destination' => 'profile',
        ]);
    }
}
