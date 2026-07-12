<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ConnectedAccountController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('auth/social/google', [AuthController::class, 'google'])->middleware('throttle:auth-social');
Route::post('auth/social/facebook', [AuthController::class, 'facebook'])->middleware('throttle:auth-social');
Route::post('auth/social/apple', [AuthController::class, 'apple'])->middleware('throttle:auth-social');

// Unauthenticated — completes a login that stopped at {"requires_two_factor": true, ...}
// from auth/login or one of the auth/social/* endpoints above. IP-keyed rate limit
// since there's no Sanctum-authenticated user yet at this point; this is the brute-force
// surface for guessing a 6-digit TOTP code.
Route::post('auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor-challenge');

Route::middleware(['auth:sanctum', 'auth.user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('throttle:auth-logout');
    Route::post('auth/password', [AuthController::class, 'setPassword'])->middleware('throttle:auth-password');

    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:account-manage');
    Route::post('account/email', [AccountController::class, 'changeEmail'])->middleware('throttle:account-manage');
    // Sends the step-up email code (StepUpService) — only for an account with neither a
    // password nor 2FA. Tightly throttled: this is a brute-force/spam surface (mailing
    // yourself codes) same in spirit as the 2FA challenge limiter.
    Route::post('account/confirmation-code', [AccountController::class, 'sendConfirmationCode'])->middleware('throttle:account-manage');

    Route::get('connected-accounts', [ConnectedAccountController::class, 'index'])->middleware('throttle:reads');
    Route::delete('connected-accounts/{provider}', [ConnectedAccountController::class, 'destroy'])->middleware('throttle:account-manage');

    Route::get('settings', [SettingsController::class, 'show'])->middleware('throttle:settings-manage');
    Route::patch('settings', [SettingsController::class, 'update'])->middleware('throttle:settings-manage');

    Route::get('two-factor', [TwoFactorController::class, 'show'])->middleware('throttle:two-factor-manage');
    // Enable returns everything (QR + recovery codes) in this one response rather than
    // Fortify's own multi-request web flow — see TwoFactorService::enable()'s doc comment.
    Route::post('two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:two-factor-manage');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor-manage');
    Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:two-factor-manage');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:two-factor-manage');

    Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:reads');

    Route::patch('profile', [ProfileController::class, 'update'])->middleware('throttle:writes-moderate');

    Route::post('devices/push-token', [PushTokenController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('devices/push-token', [PushTokenController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::get('sessions', [SessionController::class, 'index'])->middleware('throttle:sessions-manage');
    Route::delete('sessions/{tokenId}', [SessionController::class, 'destroy'])->whereNumber('tokenId')->middleware('throttle:sessions-manage');
    Route::post('sessions/revoke-others', [SessionController::class, 'revokeOthers'])->middleware('throttle:sessions-manage');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('throttle:reads');
    Route::get('users/{user}/posts', [UserController::class, 'posts'])->middleware('throttle:reads');
    Route::get('users/{user}/top-tags', [UserController::class, 'topTags'])->middleware('throttle:reads');

    Route::post('users/{user}/follow', [FollowController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('users/{user}/follow', [FollowController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('users/{user}/followers', [FollowController::class, 'followers'])->middleware('throttle:reads');
    Route::get('users/{user}/following', [FollowController::class, 'following'])->middleware('throttle:reads');

    Route::post('posts', [PostController::class, 'store'])->middleware('throttle:posts-store');
    Route::get('posts/{post}', [PostController::class, 'show'])->middleware('throttle:reads');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::post('posts/{post}/like', [LikeController::class, 'store'])->middleware('throttle:reads');
    Route::delete('posts/{post}/like', [LikeController::class, 'destroy'])->middleware('throttle:reads');

    Route::get('posts/{post}/comments', [CommentController::class, 'index'])->middleware('throttle:reads');
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:notifications-read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:notifications-mark-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('throttle:notifications-read');

    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:reports');
});
