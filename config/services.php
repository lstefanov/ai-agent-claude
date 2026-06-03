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

    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        'results_count' => env('BRAVE_RESULTS_COUNT', 10),
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
