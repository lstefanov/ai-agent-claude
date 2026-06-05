<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'generator_model' => env('OLLAMA_GENERATOR_MODEL', 'mistral'),
        'fallback_model' => env('OLLAMA_DEFAULT_FALLBACK', 'llama3.1:8b'),
        // Auto-pull missing agent models before a flow run. Disabled in tests.
        'auto_pull' => env('OLLAMA_AUTO_PULL', true),
        // Strong model used by FinalComposerService to assemble the final result
        // from the individual agent outputs (posts + titles + hashtags).
        'composer_model' => env('OLLAMA_COMPOSER_MODEL', 'gemma3:12b'),
    ],

    // Which LLM provider auto-generates agents for a Flow: ollama | anthropic | openai.
    // Ollama stays the default; external providers need the matching api_key below.
    'generator' => [
        'provider' => env('GENERATOR_PROVIDER', 'ollama'),
    ],

    'anthropic' => [
        'api_key'  => env('ANTHROPIC_API_KEY'),
        'model'    => env('ANTHROPIC_GENERATOR_MODEL', 'claude-sonnet-4-6'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version'  => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    'openai' => [
        'api_key'  => env('OPENAI_API_KEY'),
        'model'    => env('OPENAI_GENERATOR_MODEL', 'gpt-4o'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    ],

    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        'results_count' => env('BRAVE_RESULTS_COUNT', 10),
    ],

    'google_places' => [
        // Google Places API (New) key — used for business reviews/rating.
        // Enable "Places API (New)" in Google Cloud Console for the key.
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'comfyui' => [
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
        'enabled' => env('COMFYUI_ENABLED', true),
        'checkpoint' => env('COMFYUI_CHECKPOINT', 'sd_xl_base_1.0.safetensors'),
        'negative_prompt' => env('COMFYUI_NEGATIVE_PROMPT', 'ugly, deformed, noisy, blurry, distorted, low quality, watermark, text, signature'),
    ],

    'crawl' => [
        'url' => env('CRAWL_SERVICE_URL', 'http://localhost:8189'),
        'enabled' => env('CRAWL_SERVICE_ENABLED', true),
        'timeout' => env('CRAWL_SERVICE_TIMEOUT', 15),
        'max_pages' => env('CRAWL_MAX_PAGES', 20),
    ],

];
