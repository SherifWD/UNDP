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

    'isendly' => [
        'enabled' => (bool) env('ISENDLY_ENABLED', false),
        'base_url' => env('ISENDLY_BASE_URL', 'https://api.isend.ly'),
        'api_key' => env('ISENDLY_API_KEY'),
        'sender_id' => env('ISENDLY_SENDER_ID', 'UNDP'),
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'legacy_endpoint' => env('FCM_LEGACY_ENDPOINT', 'https://fcm.googleapis.com/fcm/send'),
    ],

];
