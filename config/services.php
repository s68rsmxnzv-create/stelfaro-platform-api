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
        'internal_token' => env('DTE_CORE_TOKEN'),
        'admin_email' => env('DTE_CORE_ADMIN_EMAIL', 'admin@stelfaro.com'),
        'admin_role' => env('DTE_CORE_ADMIN_ROLE', 'admin_fiscal'),
        'admin_device_name' => env('DTE_CORE_ADMIN_DEVICE_NAME', 'stelfaro-platform-admin'),
    ],

    'notifications' => [
        'base_url' => env('NOTIFICATIONS_BASE_URL', 'https://admin.stelfaro.com/notifications-api/v1'),
        'internal_token' => env('NOTIFICATIONS_INTERNAL_TOKEN'),
    ],

    'wompi' => [
        'app_id' => env('WOMPI_APP_ID'),
        'api_secret' => env('WOMPI_API_SECRET'),
        'professional_plan_key' => env('WOMPI_PROFESSIONAL_PLAN_KEY', 'pro'),
        'professional_annual_price_cents' => (int) env('WOMPI_PROFESSIONAL_ANNUAL_PRICE_CENTS', 19900),
        'professional_annual_amount' => env('WOMPI_PROFESSIONAL_ANNUAL_AMOUNT'),
        'payment_links' => [
            'emprendedor' => [
                'link_id' => env('WOMPI_EMPRENDEDOR_PAYMENT_LINK_ID', 'bdfd9af6-ace2-48b1-92e4-07bd182619db'),
                'plan_key' => env('WOMPI_EMPRENDEDOR_PLAN_KEY', 'starter'),
                'price_cents' => (int) env('WOMPI_EMPRENDEDOR_ANNUAL_PRICE_CENTS', 9900),
                'expected_amount' => env('WOMPI_EMPRENDEDOR_ANNUAL_AMOUNT'),
            ],
            'profesional' => [
                'link_id' => env('WOMPI_PROFESSIONAL_PAYMENT_LINK_ID', '33bcab4e-0036-4477-a0a0-326a4a415c31'),
                'plan_key' => env('WOMPI_PROFESSIONAL_PLAN_KEY', 'pro'),
                'price_cents' => (int) env('WOMPI_PROFESSIONAL_ANNUAL_PRICE_CENTS', 19900),
                'expected_amount' => env('WOMPI_PROFESSIONAL_ANNUAL_AMOUNT'),
            ],
        ],
    ],

];
