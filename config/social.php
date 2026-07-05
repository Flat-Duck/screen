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

];
