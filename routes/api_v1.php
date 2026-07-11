<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('auth/social/google', [AuthController::class, 'google'])->middleware('throttle:auth-social');
Route::post('auth/social/facebook', [AuthController::class, 'facebook'])->middleware('throttle:auth-social');
Route::post('auth/social/apple', [AuthController::class, 'apple'])->middleware('throttle:auth-social');

Route::middleware(['auth:sanctum', 'auth.user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('throttle:auth-logout');
    Route::post('auth/password', [AuthController::class, 'setPassword'])->middleware('throttle:auth-password');

    Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:reads');

    Route::patch('profile', [ProfileController::class, 'update'])->middleware('throttle:writes-moderate');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('throttle:reads');
    Route::get('users/{user}/posts', [UserController::class, 'posts'])->middleware('throttle:reads');

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
