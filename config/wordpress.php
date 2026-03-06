<?php

return [
    'base_url' => env('WP_API_BASE_URL', 'http://localhost:8000/wp-json/wp/v2'),
    'author_id' => env('WP_API_AUTHOR_ID', 3),
    'per_page' => env('WP_API_PER_PAGE', 100),
    'cache_duration' => env('WP_API_CACHE_DURATION', 60), // minutes


    'image_disk' => env('WP_API_FILESYSTEM_DISK', 'public'), // filesystem disk for cached images
 
    /*
    |--------------------------------------------------------------------------
    | Preserve Original Structure
    |--------------------------------------------------------------------------
    |
    | When enabled, preserves the original WordPress year/month folder structure
    | and filename for SEO-friendly URLs. When disabled, uses hashed filenames.
    |
    */
    'preserve_structure' => true,

    /*
    |--------------------------------------------------------------------------
    | WordPress Application Password Authentication
    |--------------------------------------------------------------------------
    |
    | If your WordPress site requires authentication, you can use Application
    | Passwords (available in WordPress 5.6+). Generate one in WP Admin:
    | Users > Your Profile > Application Passwords
    |
    */
    'auth' => [
        'enabled' => env('WP_API_AUTH_ENABLED', false),
        'username' => env('WP_API_USERNAME'),
        'password' => env('WP_API_APP_PASSWORD'),
    ],
];
