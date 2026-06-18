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

    'dte_core' => [
        'base_url' => env('DTE_CORE_BASE_URL', 'https://dte.stelfaro.me/api/v1'),
        'bridge_password' => env('DTE_CORE_BRIDGE_PASSWORD'),
        'admin_email' => env('DTE_CORE_ADMIN_EMAIL', 'admin@stelfaro.com'),
        'admin_device_name' => env('DTE_CORE_ADMIN_DEVICE_NAME', 'stelfaro-platform-admin'),
    ],

    'notifications' => [
        'base_url' => env('NOTIFICATIONS_BASE_URL', 'https://admin.stelfaro.com/notifications-api/v1'),
        'internal_token' => env('NOTIFICATIONS_INTERNAL_TOKEN'),
    ],

];
