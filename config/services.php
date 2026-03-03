<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location to locate service credentials.
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

    'gateway' => [
        'api_key' => env('SWOT_GATEWAY_API_KEY', env('SWOT_API_KEY')),
    ],

    'swot' => [
        'api_key' => env('SWOT_API_KEY', env('SWOT_GATEWAY_API_KEY')),
    ],

    'brain' => [
        'base_uri' => env('BRAIN_BASE_URI', 'http://alphago-brain:8000'),
        'api_key' => env('BRAIN_API_KEY'),
        'timeout' => env('BRAIN_API_TIMEOUT', 120),
    ],

];
