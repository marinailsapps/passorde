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

    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // SMS to the client when an order is sent (server.sms.enabled, SendOrderSmsJob).
    'ovh' => [
        'endpoint' => env('OVH_ENDPOINT', 'https://eu.api.ovh.com/1.0'),
        'app_key' => env('OVH_APP_KEY'),
        'app_secret' => env('OVH_APP_SECRET'),
        'consumer_key' => env('OVH_CONSUMER_KEY'),
        'sms_service' => env('OVH_SMS_SERVICE'),
        // A sender name once OVH validated it (e.g. the brand in capitals); empty = OVH's short number (senderForResponse).
        'sms_sender' => env('OVH_SMS_SENDER') ?: null,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
