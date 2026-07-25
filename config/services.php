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

    // Firecrawl — structured scraping of company career pages (T-20/T-23).
    // Project-level key (not BYOK). Empty key disables live scraping locally.
    'firecrawl' => [
        'key' => env('FIRECRAWL_API_KEY'),
        'base_url' => env('FIRECRAWL_BASE_URL', 'https://api.firecrawl.dev'),
        'timeout' => (int) env('FIRECRAWL_TIMEOUT', 60),
    ],

    // Voyage AI — job embeddings for semantic dedup/search (T-25). Owner-funded,
    // single project key. `dimensions` must match the pgvector column (1024).
    'voyage' => [
        'key' => env('VOYAGE_API_KEY'),
        'base_url' => env('VOYAGE_BASE_URL', 'https://api.voyageai.com/v1'),
        'model' => env('VOYAGE_MODEL', 'voyage-3-lite'),
        'dimensions' => (int) env('VOYAGE_DIMENSIONS', 1024),
        'timeout' => (int) env('VOYAGE_TIMEOUT', 30),
    ],

];
