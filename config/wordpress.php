<?php

/**
 * Laravel WordPress CDA Configuration.
 *
 * Configuration options for the WordPress REST API integration.
 * These settings control how the Laravel application connects to and
 * fetches content from a WordPress headless CMS backend.
 *
 * Environment variables can be used to override these defaults.
 * See the .env.example or README.md for available options.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | API Connection Settings
    |--------------------------------------------------------------------------
    |
    | Core configuration for connecting to the WordPress REST API.
    |
    */

    /**
     * The base URL for the WordPress REST API.
     *
     * This should be the full URL to your WordPress site's JSON API endpoint.
     * Typically: https://your-wordpress-site.com/wp-json/wp/v2
     *
     * @var string
     */
    'base_url' => env('WP_API_BASE_URL', 'https://wordpress.org/wp-json/wp/v2'),

    /**
     * The WordPress author ID to filter posts by.
     *
     * When set, only posts authored by this user will be retrieved.
     * Get this from WordPress Admin > Users > User ID column.
     * Set to null to fetch all authors' posts.
     *
     * @var int|null
     */
    'author_id' => env('WP_API_AUTHOR_ID', 1),

    /**
     * Number of posts to fetch per API request.
     *
     * The WordPress API allows up to 100 posts per request.
     * Higher values reduce API calls but may slow down initial fetches.
     * Adjust based on your WordPress site's performance.
     *
     * @var int
     */
    'per_page' => env('WP_API_PER_PAGE', 100),

    /**
     * Cache duration for API responses in minutes.
     *
     * How long to cache WordPress API responses before refetching.
     * Higher values reduce API load but mean content updates take longer.
     *
     * @var int
     */
    'cache_duration' => env('WP_API_CACHE_DURATION', 60),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for caching behavior including prefix and tags.
    | This allows selective cache flushing for WordPress CDA package.
    |*/

    /**
     * Cache key prefix for this package.
     *
     * This prefix is prepended to all cache keys to isolate this
     * package's cache from other Laravel cache entries. This enables
     * selective cache clearing without affecting other cached data.
     *
     * @var string
     */
    'cache_prefix' => env('WP_API_CACHE_PREFIX', 'wp_cda_'),

    /**
     * Cache tags for this package.
     *
     * Tags allow for tag-based cache invalidation. When clearing
     * cache, you can flush all entries with specific tags.
     * Note: Cache tags require a tag-supported cache store (Redis, Memcached).
     *
     * @var array
     */
    'cache_tags' => ['wordpress_cda'],

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Settings
    |--------------------------------------------------------------------------
    |
    | Settings for HTTP requests made to the WordPress API.
    |
    */

    /**
     * User-Agent string for HTTP requests.
     *
     * Custom User-Agent header sent with all API requests.
     * Helps identify your application in WordPress server logs.
     *
     * @var string
     */
    'user_agent' => env('WP_API_USER_AGENT', 'Laravel-WordPress-CDA/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Routing & URLs
    |--------------------------------------------------------------------------
    |
    | Settings for URL generation and routing integration.
    |
    */

    /**
     * The route name for viewing blog posts.
     *
     * Used to generate post URLs when calling getUrl() on posts.
     * This route should accept a 'slug' parameter.
     *
     * @var string
     */
    'blog_view_route' => env('WP_API_BLOG_VIEW_ROUTE', 'blog.view'),

    /*
    |--------------------------------------------------------------------------
    | Image Caching Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for downloading and caching WordPress media locally.
    |
    */

    /**
     * Filesystem disk for storing cached images.
     *
     * The Laravel disk name (from config/filesystems.php) to use for
     * storing locally cached WordPress images. Must be configured
     * in your application's filesystems configuration.
     *
     * @var string
     */
    'image_disk' => env('WP_API_FILESYSTEM_DISK', 'public'),

    /**
     * Preserve original WordPress folder structure for cached images.
     *
     * When enabled (true), images are stored in year/month folders
     * matching WordPress's upload structure (e.g., 2026/03/image.jpg).
     * This maintains SEO-friendly URLs.
     *
     * When disabled (false), images are stored with hashed filenames
     * in a flat 'images/' folder structure.
     *
     * @var bool
     */
    'preserve_structure' => true,

    /*
    |--------------------------------------------------------------------------
    | Authentication Settings
    |--------------------------------------------------------------------------
    |
    | WordPress Application Password authentication for private content.
    | Requires WordPress 5.6+ with Application Passwords enabled.
    |
    */

    /**
     * Enable WordPress Application Password authentication.
     *
     * Set to true if your WordPress content requires authentication.
     * You'll also need to configure username and password below.
     *
     * @var bool
     */
    'auth' => [
        /**
         * Whether authentication is enabled.
         *
         * @var bool
         */
        'enabled' => env('WP_API_AUTH_ENABLED', false),

        /**
         * WordPress username for authentication.
         *
         * The WordPress user account that has Application Password access.
         *
         * @var string|null
         */
        'username' => env('WP_API_USERNAME'),

        /**
         * WordPress Application Password.
         *
         * Generate this in WordPress Admin > Users > Your Profile >
         * Application Passwords section. Format: xxxx xxxx xxxx xxxx
         *
         * @var string|null
         */
        'password' => env('WP_API_APP_PASSWORD'),
    ],
];
