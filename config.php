<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Content Generation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for AI content generation including timeouts, retries,
    | and default values for different content types.
    |
    */

    'generation' => [
        // Default timeout for content generation jobs (in seconds)
        // Can be overridden per content type
        'default_timeout' => env('CONTENT_GENERATION_TIMEOUT', 300),

        // Timeouts per content type (in seconds)
        'timeouts' => [
            'help_article' => env('CONTENT_TIMEOUT_HELP_ARTICLE', 180),
            'blog_post' => env('CONTENT_TIMEOUT_BLOG_POST', 240),
            'landing_page' => env('CONTENT_TIMEOUT_LANDING_PAGE', 300),
            'social_post' => env('CONTENT_TIMEOUT_SOCIAL_POST', 60),
        ],

        // Number of retry attempts for failed generation
        'max_retries' => env('CONTENT_GENERATION_RETRIES', 3),

        // Backoff intervals between retries (in seconds)
        'backoff' => [30, 60, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Revision Settings
    |--------------------------------------------------------------------------
    |
    | Settings for content revision history and pruning.
    |
    */

    'revisions' => [
        // Maximum number of revisions to keep per content item
        // Set to 0 or null to keep unlimited revisions
        'max_per_item' => env('CONTENT_MAX_REVISIONS', 50),

        // Maximum age of revisions to keep (in days)
        // Set to 0 or null to keep revisions indefinitely
        'max_age_days' => env('CONTENT_REVISION_MAX_AGE', 180),

        // Whether to keep published revisions regardless of age/count limits
        'preserve_published' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for content caching.
    |
    */

    'cache' => [
        // Cache TTL in seconds (1 hour in production, 1 minute in dev)
        'ttl' => env('CONTENT_CACHE_TTL', 3600),

        // Prefix for cache keys
        'prefix' => 'content:render',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for content search functionality.
    |
    | Supported backends:
    | - 'database' (default): LIKE-based search with relevance scoring
    | - 'scout_database': Laravel Scout with database driver
    | - 'meilisearch': Laravel Scout with Meilisearch driver
    |
    */

    'search' => [
        // Search backend to use
        // Options: 'database', 'scout_database', 'meilisearch'
        'backend' => env('CONTENT_SEARCH_BACKEND', 'database'),

        // Minimum query length for search
        'min_query_length' => 2,

        // Maximum results per page
        'max_per_page' => 50,

        // Default results per page
        'default_per_page' => 20,

        // Rate limiting for search API (requests per minute)
        'rate_limit' => env('CONTENT_SEARCH_RATE_LIMIT', 60),
    ],
];
