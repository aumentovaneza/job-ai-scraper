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

    /*
    | Anthropic Claude (per-user BYOK). Keys are supplied by each user and stored
    | encrypted on users.encrypted_anthropic_key — nothing here is a secret. This
    | block only holds routing, the default model, retry policy, and the pricing
    | table used to estimate ai_calls.cost_cents (PLAN.md §7, T-10/T-11).
    */
    'anthropic' => [
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

        // Model for real user-facing work (enrichment, letters). Opus 4.8 per the
        // claude-api guidance; override per-deployment if cost dictates.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),

        // A cheap, fast model is enough for the BYOK liveness ping (T-10 verify()).
        'verify_model' => env('ANTHROPIC_VERIFY_MODEL', 'claude-haiku-4-5'),

        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),
        'retry_base_ms' => (int) env('ANTHROPIC_RETRY_BASE_MS', 500),

        // Cost per 1M tokens, in cents (input, output). Used to estimate cost_cents
        // on every AiCall so spend caps can be enforced. Keep in sync with pricing.
        'pricing' => [
            'claude-opus-4-8' => ['input' => 500, 'output' => 2500],
            'claude-opus-4-7' => ['input' => 500, 'output' => 2500],
            'claude-sonnet-5' => ['input' => 300, 'output' => 1500],
            'claude-sonnet-4-6' => ['input' => 300, 'output' => 1500],
            'claude-haiku-4-5' => ['input' => 100, 'output' => 500],
        ],
    ],

];
