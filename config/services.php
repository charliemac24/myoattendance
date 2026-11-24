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

    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
        'timeout' => (int) env('SEMAPHORE_TIMEOUT', 15),
        'batch_size' => (int) env('SMS_PROCESS_BATCH_SIZE', 50),
        'max_attempts' => (int) env('SMS_MAX_ATTEMPTS', 3),
        'stale_after_minutes' => (int) env('SMS_PROCESSING_STALE_AFTER', 10),
        'retry_delays' => [60, 300],
    ],

    // Automatic SMS price estimate shown to schools. Placeholders until the business sets real values.
    'sms_estimate' => [
        'price_per_text' => env('SMS_ESTIMATE_PRICE_PER_TEXT', '0.50'),
        'round_to' => env('SMS_ESTIMATE_ROUND_TO', '50'),
    ],

    // Emails to super admin about payments to review, SMS price requests and new sign-ups.
    // Off by default; ALERT_EMAIL_TO is comma-separated and falls back to every super admin's email.
    'admin_alerts' => [
        'enabled' => (bool) env('ALERT_EMAILS', false),
        'to' => env('ALERT_EMAIL_TO'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

];
