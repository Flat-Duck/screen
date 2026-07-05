<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'auth.user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('throttle:30,1');

    Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:60,1');

    Route::patch('profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('throttle:60,1');
    Route::get('users/{user}/posts', [UserController::class, 'posts'])->middleware('throttle:60,1');

    Route::post('users/{user}/follow', [FollowController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('users/{user}/follow', [FollowController::class, 'destroy'])->middleware('throttle:30,1');
    Route::get('users/{user}/followers', [FollowController::class, 'followers'])->middleware('throttle:60,1');
    Route::get('users/{user}/following', [FollowController::class, 'following'])->middleware('throttle:60,1');

    Route::post('posts', [PostController::class, 'store'])->middleware('throttle:10,1');
    Route::get('posts/{post}', [PostController::class, 'show'])->middleware('throttle:60,1');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->middleware('throttle:30,1');

    Route::post('posts/{post}/like', [LikeController::class, 'store'])->middleware('throttle:60,1');
    Route::delete('posts/{post}/like', [LikeController::class, 'destroy'])->middleware('throttle:60,1');

    Route::get('posts/{post}/comments', [CommentController::class, 'index'])->middleware('throttle:60,1');
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware('throttle:30,1');
});
