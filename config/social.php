<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | Screenshot uploads (originals + thumbnails) are stored here. Deliberately
    | separate from FILESYSTEM_DISK (which stays 'local') because only the
    | 'public' disk is web-servable via `storage:link`. Swapping to 's3' later
    | is a config-only change.
    |
    */

    'media_disk' => env('SOCIAL_MEDIA_DISK', 'public'),

    'media_cleanup_grace_minutes' => (int) env('SOCIAL_MEDIA_CLEANUP_GRACE_MINUTES', 60),

    'media_job_timeout_seconds' => (int) env('SOCIAL_MEDIA_JOB_TIMEOUT_SECONDS', 60),

    'media_job_backoff_seconds' => [30, 120, 300],

    'processing' => [
        'analysis_ttl_minutes' => (int) env('SOCIAL_MEDIA_ANALYSIS_TTL_MINUTES', 30),
        'ocr' => [
            'binary' => env('SOCIAL_OCR_BINARY', 'tesseract'),
            'language' => env('SOCIAL_OCR_LANGUAGE', 'eng'),
            'timeout_seconds' => (int) env('SOCIAL_OCR_TIMEOUT_SECONDS', 45),
            'max_characters' => (int) env('SOCIAL_OCR_MAX_CHARACTERS', 50_000),
        ],
        'safety' => [
            // Detection only sets a warning state. Matched text is never returned or logged.
            'patterns' => [
                'credential' => [
                    '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
                    '/\b(?:api[_-]?key|secret|password|token)\s*[:=]\s*\S+/i',
                ],
                'email_address' => ['/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
                'payment_card' => ['/\b(?:\d[ -]*?){13,19}\b/'],
                'government_id' => ['/\b\d{3}-\d{2}-\d{4}\b/'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Post Retention
    |--------------------------------------------------------------------------
    |
    | Soft-deleted posts are recoverable/inspectable until they're this many days
    | old, at which point `posts:prune-deleted` (scheduled daily) force-deletes
    | them and removes their media files from disk.
    |
    */

    'post_retention_days' => env('SOCIAL_POST_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Account Retention
    |--------------------------------------------------------------------------
    |
    | Mirrors post_retention_days above: a soft-deleted account (see AccountService)
    | is recoverable via `users:restore {id}` until it's this many days old, at
    | which point `users:prune-deleted` (scheduled daily) force-deletes it — cascading
    | its posts/likes/comments/follows/social_accounts/device_push_tokens/passkeys at
    | the DB level — and cleans up what the DB cascade can't (avatar file, post media
    | files, its notifications inbox).
    |
    */

    'account_retention_days' => env('SOCIAL_ACCOUNT_RETENTION_DAYS', 30),

    'message_request_rejection_cooldown_days' => (int) env('SOCIAL_MESSAGE_REQUEST_REJECTION_COOLDOWN_DAYS', 30),

    'analytics' => [
        // Raw event rows are intentionally short-lived. Milestone 4.2 aggregates will
        // outlive them without retaining per-event behavioral history indefinitely.
        'raw_event_retention_days' => (int) env('SOCIAL_ANALYTICS_RETENTION_DAYS', 90),
    ],

    'recommendations' => [
        // Bump this prefix when pool semantics change; old keys then expire naturally.
        'hot_pool_prefix' => env('SOCIAL_RECOMMENDATION_POOL_PREFIX', 'recommendations:v1:hot'),
        'hot_pool_ttl_minutes' => (int) env('SOCIAL_RECOMMENDATION_POOL_TTL_MINUTES', 60),
        'total_limit' => (int) env('SOCIAL_RECOMMENDATION_TOTAL_LIMIT', 250),
        'page_size' => 15,
        'ranking_version' => env('SOCIAL_RECOMMENDATION_RANKING_VERSION', 'v1'),
        'feed_session_ttl_minutes' => (int) env('SOCIAL_RECOMMENDATION_SESSION_TTL_MINUTES', 30),
        'two_hop_author_limit' => 100,
        'new_creator_max_followers' => 100,
        'diversity' => [
            'max_per_author' => 2,
            'max_per_category' => 4,
            'max_source_share' => 0.5,
        ],
        'source_limits' => [
            'following' => 100,
            'onboarding_interest' => 80,
            'followed_hashtag' => 50,
            'category' => 50,
            'trending' => 50,
            'regional_trending' => 30,
            'two_hop' => 40,
            'similar_author' => 30,
            'similar_topic' => 40,
            'new_creator' => 20,
        ],
        'windows' => [
            'following_days' => 14,
            'interest_days' => 30,
            'trending_days' => 7,
            'two_hop_days' => 14,
            'similar_author_days' => 30,
            'similar_topic_days' => 45,
            'new_creator_days' => 90,
        ],
    ],

    // Deployments can supply a comma-separated, policy-reviewed lexicon. User-defined
    // hidden terms work independently and are the primary filter mechanism.
    'offensive_terms' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOCIAL_OFFENSIVE_TERMS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Trending / Discovery
    |--------------------------------------------------------------------------
    |
    | A deliberately small-scale stand-in for a real ranking pipeline: no ML,
    | no embeddings, just a Hacker-News-style "engagement decayed by age"
    | formula recomputed periodically (`posts:refresh-trending`) into a Redis
    | sorted set. FeedService blends a few of the top-scoring posts from
    | accounts the viewer doesn't follow into the first page of their feed —
    | see FeedService::injectDiscovery(). Fails open: if Redis is unreachable
    | the feed just falls back to pure in-network/chronological, never a 500.
    |
    */

    'trending' => [
        // Only posts newer than this are eligible — keeps the scoring job's
        // working set small regardless of how large `posts` grows over time.
        'window_days' => env('SOCIAL_TRENDING_WINDOW_DAYS', 7),

        'like_weight' => env('SOCIAL_TRENDING_LIKE_WEIGHT', 3),
        'comment_weight' => env('SOCIAL_TRENDING_COMMENT_WEIGHT', 5),

        // Higher = older posts fall off faster, regardless of engagement.
        // 1.8 matches Hacker News's own "gravity" constant.
        'gravity' => env('SOCIAL_TRENDING_GRAVITY', 1.8),

        'redis_key' => env('SOCIAL_TRENDING_REDIS_KEY', 'trending:posts'),

        // If the scheduled job ever stops running (cron down, Redis blip),
        // the set expires instead of silently going stale forever.
        'safety_ttl_minutes' => env('SOCIAL_TRENDING_SAFETY_TTL_MINUTES', 60),

        // 0-indexed slot positions in the first feed page where a discovery
        // post gets spliced in, clamped to however many posts are actually
        // on that page.
        'discovery_positions' => [3, 8],
    ],

];
