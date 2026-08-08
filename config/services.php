<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Consumed by Socialite (App\Services\SocialAuth\GoogleTokenVerifier) to call Google's
    // userinfo endpoint with the access token Android obtains via its Authorization API.
    // 'redirect' is unused — this app never does Socialite's redirect-based OAuth flow for
    // Google, only the stateless userFromToken() call, but Socialite's provider constructor
    // requires the key to be present.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => null,
    ],

    // 'client_id'/'client_secret' are Socialite's own naming convention (consumed by
    // GoogleTokenVerifier's Facebook counterpart); 'app_id'/'app_secret' are this app's
    // pre-existing names, still read directly by FacebookTokenVerifier::assertTokenBelongsToThisApp.
    // Both pairs point at the same two credentials — not a duplicated secret, just two names
    // for the same env vars so neither piece of code needs to change its own convention.
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect' => null,
    ],

    // Audience ("aud" claim) the Apple identity token must have — the Services ID
    // (or app bundle ID) configured for Sign in with Apple.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
    ],

    // Firebase Cloud Messaging (push notifications). Both must be set for FcmChannel to
    // actually send anything — see App\Services\Fcm\FcmClient::isConfigured(). Missing
    // credentials are not an error; push is skipped silently, same as this app runs fine
    // without the social-login provider credentials above until they're configured.
    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],

];
