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

];
