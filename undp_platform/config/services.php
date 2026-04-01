<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file stores credentials for third party services such as mail,
    | push providers, OTP providers, and cloud storage integrations.
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

    'resala' => [
        'enabled' => (bool) env('RESALA_ENABLED', false),
        'base_url' => env('RESALA_BASE_URL', 'https://dev.resala.ly/api/v1'),
        'token' => env('RESALA_AUTH_TOKEN'),
        'service_name' => env('RESALA_SERVICE_NAME', env('APP_NAME', 'UNDP')),
        'autofill_signature' => env('RESALA_AUTOFILL_SIGNATURE'),
        'region' => env('RESALA_REGION'),
        'test_mode' => (bool) env('RESALA_TEST_MODE', false),
        'timeout_seconds' => env('RESALA_TIMEOUT_SECONDS', 10),
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'legacy_endpoint' => env('FCM_LEGACY_ENDPOINT', 'https://fcm.googleapis.com/fcm/send'),
    ],

];
