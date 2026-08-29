<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MobilePasswordResetController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        return response()->view('auth.mobile-password-reset', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }
}
