<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlockController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ConnectedAccountController;
use App\Http\Controllers\Api\V1\ContentAnalyticsController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationMessageController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\ExploreController;
use App\Http\Controllers\Api\V1\FeatureConfigurationController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\FollowRequestController;
use App\Http\Controllers\Api\V1\HashtagController;
use App\Http\Controllers\Api\V1\HiddenTermController;
use App\Http\Controllers\Api\V1\InterestController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\MediaAnalysisController;
use App\Http\Controllers\Api\V1\MuteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\PostLibraryController;
use App\Http\Controllers\Api\V1\PostMediaController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\RecommendationFeedbackController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RepostController;
use App\Http\Controllers\Api\V1\SavedCollectionController;
use App\Http\Controllers\Api\V1\SavedPostController;
use App\Http\Controllers\Api\V1\ScreenshotCategoryController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TelemetryController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('devices/enroll', [DeviceController::class, 'enroll'])->middleware('throttle:20,1');

Route::middleware(['auth:sanctum', 'auth.device:device:manage'])->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('auth/social/google', [AuthController::class, 'google'])->middleware('throttle:auth-social');
    Route::post('auth/social/facebook', [AuthController::class, 'facebook'])->middleware('throttle:auth-social');
    Route::post('auth/social/apple', [AuthController::class, 'apple'])->middleware('throttle:auth-social');
    Route::post('auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor-challenge');
});

Route::middleware(['auth:sanctum', 'auth.device:push-token:write'])->group(function () {
    Route::put('devices/push-token', [PushTokenController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('devices/push-token', [PushTokenController::class, 'destroy'])->middleware('throttle:writes-moderate');
});

Route::post('telemetry/events', [TelemetryController::class, 'events'])
    ->middleware(['auth:sanctum', 'auth.device:telemetry:write', 'telemetry.size', 'throttle:120,1']);

Route::middleware(['auth:sanctum', 'auth.user', 'session.touch'])->group(function () {
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
    Route::get('hidden-terms', [HiddenTermController::class, 'index'])->middleware('throttle:settings-manage');
    Route::post('hidden-terms', [HiddenTermController::class, 'store'])->middleware('throttle:settings-manage');
    Route::delete('hidden-terms/{hiddenTerm}', [HiddenTermController::class, 'destroy'])->middleware('throttle:settings-manage');
    Route::get('onboarding/interests', [InterestController::class, 'index'])->middleware('throttle:reads');
    Route::post('onboarding/interests/skip', [InterestController::class, 'skip'])->middleware('throttle:settings-manage');
    Route::get('me/interests', [InterestController::class, 'selected'])->middleware('throttle:reads');
    Route::put('me/interests', [InterestController::class, 'update'])->middleware('throttle:settings-manage');

    Route::get('two-factor', [TwoFactorController::class, 'show'])->middleware('throttle:two-factor-manage');
    // Enable returns everything (QR + recovery codes) in this one response rather than
    // Fortify's own multi-request web flow — see TwoFactorService::enable()'s doc comment.
    Route::post('two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:two-factor-manage');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor-manage');
    Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:two-factor-manage');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:two-factor-manage');

    Route::get('feed/following', [FeedController::class, 'following'])->middleware('throttle:reads');
    Route::get('feed/for-you', [FeedController::class, 'forYou'])->middleware('throttle:reads');
    Route::get('feed', [FeedController::class, 'index'])->middleware('throttle:reads');
    Route::delete('recommendations/profile', [RecommendationFeedbackController::class, 'reset'])->middleware('throttle:settings-manage');
    Route::get('feature-configuration', FeatureConfigurationController::class)->middleware('throttle:reads');
    Route::get('explore', [ExploreController::class, 'index'])->middleware('throttle:reads');
    Route::post('analytics/content-events', [ContentAnalyticsController::class, 'store'])
        ->middleware(['analytics.size', 'throttle:content-analytics']);
    Route::get('screenshot-categories', [ScreenshotCategoryController::class, 'index'])->middleware('throttle:reads');
    Route::post('media/analyses', [MediaAnalysisController::class, 'store'])->middleware('throttle:posts-store');
    Route::get('media/analyses/{token}', [MediaAnalysisController::class, 'show'])->middleware('throttle:reads');
    Route::post('media/analyses/{token}/publish', [MediaAnalysisController::class, 'publish'])->middleware('throttle:posts-store');
    Route::delete('media/analyses/{token}', [MediaAnalysisController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::patch('profile', [ProfileController::class, 'update'])->middleware('throttle:writes-moderate');

    Route::get('sessions', [SessionController::class, 'index'])->middleware('throttle:sessions-manage');
    Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])->whereUuid('sessionId')->middleware('throttle:sessions-manage');
    Route::post('sessions/revoke-others', [SessionController::class, 'revokeOthers'])->middleware('throttle:sessions-manage');

    Route::get('search/users', [SearchController::class, 'users'])->middleware('throttle:search');
    Route::get('search/posts', [SearchController::class, 'posts'])->middleware('throttle:search');
    Route::get('search/hashtags', [SearchController::class, 'hashtags'])->middleware('throttle:search');

    // 'followed' must be registered before the {hashtag} wildcard route below, otherwise
    // Laravel would try to resolve "followed" as a hashtag name.
    Route::get('hashtags/followed', [HashtagController::class, 'followed'])->middleware('throttle:reads');
    Route::get('hashtags/{hashtag}', [HashtagController::class, 'show'])->middleware('throttle:reads');
    Route::get('hashtags/{hashtag}/posts', [HashtagController::class, 'posts'])->middleware('throttle:reads');
    Route::post('hashtags/{hashtag}/follow', [HashtagController::class, 'follow'])->middleware('throttle:writes-moderate');
    Route::delete('hashtags/{hashtag}/follow', [HashtagController::class, 'unfollow'])->middleware('throttle:writes-moderate');
    Route::post('hashtags/{hashtag}/show-fewer', [RecommendationFeedbackController::class, 'showFewerFromHashtag'])->middleware('throttle:writes-moderate');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('throttle:reads');
    Route::get('users/{user}/posts', [UserController::class, 'posts'])->middleware('throttle:reads');
    Route::get('users/{user}/top-tags', [UserController::class, 'topTags'])->middleware('throttle:reads');
    Route::get('users/{user}/reposts', [RepostController::class, 'forUser'])->middleware('throttle:reads');

    Route::post('users/{user}/follow', [FollowController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('users/{user}/follow', [FollowController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('users/{user}/followers', [FollowController::class, 'followers'])->middleware('throttle:reads');
    Route::get('users/{user}/following', [FollowController::class, 'following'])->middleware('throttle:reads');

    Route::post('users/{user}/follow-requests', [FollowRequestController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('users/{user}/follow-requests', [FollowRequestController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('follow-requests/incoming', [FollowRequestController::class, 'incoming'])->middleware('throttle:reads');
    Route::get('follow-requests/outgoing', [FollowRequestController::class, 'outgoing'])->middleware('throttle:reads');
    Route::post('follow-requests/{followRequest}/accept', [FollowRequestController::class, 'accept'])->middleware('throttle:writes-moderate');
    Route::post('follow-requests/{followRequest}/decline', [FollowRequestController::class, 'decline'])->middleware('throttle:writes-moderate');

    Route::post('users/{user}/block', [BlockController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('users/{user}/block', [BlockController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('blocked-users', [BlockController::class, 'index'])->middleware('throttle:reads');

    Route::post('users/{user}/mute', [MuteController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('users/{user}/mute', [MuteController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('muted-users', [MuteController::class, 'index'])->middleware('throttle:reads');
    Route::post('users/{user}/show-fewer', [RecommendationFeedbackController::class, 'showFewerFromUser'])->middleware('throttle:writes-moderate');

    Route::post('posts', [PostController::class, 'store'])->middleware('throttle:posts-store');
    Route::get('posts/{post}', [PostController::class, 'show'])->middleware('throttle:reads');
    Route::patch('posts/{post}', [PostController::class, 'update'])->middleware('throttle:writes-moderate');
    Route::patch('posts/{post}/media/{media}', [PostMediaController::class, 'update'])->middleware('throttle:writes-moderate');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::post('posts/{postId}/archive', [PostLibraryController::class, 'archive'])->whereNumber('postId')->middleware('throttle:writes-moderate');
    Route::delete('posts/{postId}/archive', [PostLibraryController::class, 'unarchive'])->whereNumber('postId')->middleware('throttle:writes-moderate');
    Route::get('archived-posts', [PostLibraryController::class, 'archived'])->middleware('throttle:reads');
    Route::get('recently-deleted-posts', [PostLibraryController::class, 'recentlyDeleted'])->middleware('throttle:reads');
    Route::post('posts/{postId}/restore', [PostLibraryController::class, 'restore'])->whereNumber('postId')->middleware('throttle:writes-moderate');
    Route::delete('posts/{postId}/permanently-delete', [PostLibraryController::class, 'permanentlyDelete'])->whereNumber('postId')->middleware('throttle:account-manage');

    Route::post('posts/{post}/like', [LikeController::class, 'store'])->middleware('throttle:reads');
    Route::delete('posts/{post}/like', [LikeController::class, 'destroy'])->middleware('throttle:reads');
    Route::post('posts/{post}/not-interested', [RecommendationFeedbackController::class, 'notInterested'])->middleware('throttle:writes-moderate');
    Route::delete('posts/{post}/not-interested', [RecommendationFeedbackController::class, 'restoreInterest'])->middleware('throttle:writes-moderate');
    Route::post('posts/{post}/hide', [RecommendationFeedbackController::class, 'hide'])->middleware('throttle:writes-moderate');

    Route::post('comments/{comment}/like', [LikeController::class, 'storeComment'])->middleware('throttle:reads');
    Route::delete('comments/{comment}/like', [LikeController::class, 'destroyComment'])->middleware('throttle:reads');

    Route::post('posts/{post}/save', [SavedPostController::class, 'store'])->middleware('throttle:reads');
    Route::delete('posts/{post}/save', [SavedPostController::class, 'destroy'])->middleware('throttle:reads');
    Route::get('saved-posts', [SavedPostController::class, 'index'])->middleware('throttle:reads');

    Route::get('collections', [SavedCollectionController::class, 'index'])->middleware('throttle:reads');
    Route::post('collections', [SavedCollectionController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::patch('collections/{collection}', [SavedCollectionController::class, 'update'])->middleware('throttle:writes-moderate');
    Route::delete('collections/{collection}', [SavedCollectionController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::get('collections/{collection}/posts', [SavedCollectionController::class, 'items'])->middleware('throttle:reads');
    Route::post('collections/{collection}/posts/{post}', [SavedCollectionController::class, 'addItem'])->middleware('throttle:writes-moderate');
    Route::patch('collections/{collection}/posts/{post}', [SavedCollectionController::class, 'updateItem'])->middleware('throttle:writes-moderate');
    Route::delete('collections/{collection}/posts/{post}', [SavedCollectionController::class, 'removeItem'])->middleware('throttle:writes-moderate');

    Route::post('posts/{post}/repost', [RepostController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::delete('posts/{post}/repost', [RepostController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::get('posts/{post}/comments', [CommentController::class, 'index'])->middleware('throttle:reads');
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::get('comments/{comment}/replies', [CommentController::class, 'replies'])->middleware('throttle:reads');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware('throttle:writes-moderate');

    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:notifications-read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:notifications-mark-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('throttle:notifications-read');

    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:reports');

    Route::post('conversations', [ConversationController::class, 'store'])->middleware('throttle:writes-moderate');
    Route::get('conversations', [ConversationController::class, 'index'])->middleware('throttle:reads');
    Route::get('message-requests', [ConversationController::class, 'requests'])->middleware('throttle:reads');
    Route::post('conversations/{conversation}/accept', [ConversationController::class, 'accept'])->middleware('throttle:writes-moderate');
    Route::post('conversations/{conversation}/reject', [ConversationController::class, 'reject'])->middleware('throttle:writes-moderate');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->middleware('throttle:writes-moderate');
    Route::post('conversations/{conversation}/report', [ConversationController::class, 'report'])->middleware('throttle:reports');
    Route::patch('conversations/{conversation}/read', [ConversationController::class, 'markRead'])->middleware('throttle:writes-moderate');
    Route::get('conversations/{conversation}/messages', [ConversationMessageController::class, 'index'])->middleware('throttle:reads');
    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])->middleware('throttle:messages-send');
});
