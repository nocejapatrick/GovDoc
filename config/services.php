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

    'ocr' => [
        'callback_secret' => env('OCR_CALLBACK_SECRET'),
        'callback_base' => env('OCR_CALLBACK_BASE', env('APP_URL')),
        'host' => env('OCR_SERVICE_HOST', 'http://localhost:8080'),
        'sqs_queue_url' => env('OCR_SQS_QUEUE_URL'),
    ],

    'opensearch' => [
        'host' => env('OPENSEARCH_HOST', 'http://localhost:9200'),
        'index' => env('OPENSEARCH_INDEX', 'documents'),
    ],

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'qwen2.5:7b'),
        'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
        'embed_dimensions' => env('OLLAMA_EMBED_DIMENSIONS', 768),
    ],
];
