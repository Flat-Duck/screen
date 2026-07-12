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

    // Audience ("aud" claim) the Android app's Google ID token must have — the OAuth
    // "Web application" client ID from Google Cloud Console, not the Android client ID
    // (see GoogleSignInOptions.Builder#requestIdToken on the client).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
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
