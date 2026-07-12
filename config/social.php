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
