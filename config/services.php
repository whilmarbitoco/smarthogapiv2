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

    'sinric' => [
        'base_url' => env('SINRIC_API_BASE_URL', 'https://api.sinric.pro/api/v1'),
        'client_id' => env('SINRIC_API_CLIENT_ID', 'android-app'),
        'timeout' => env('SINRIC_API_TIMEOUT', 30),
        'connect_timeout' => env('SINRIC_API_CONNECT_TIMEOUT', 10),
    ],

    'fastapi' => [
        'url' => env('FASTAPI_URL', 'https://machinelearning-ruby.vercel.app'),
        'api_key' => env('FASTAPI_API_KEY'),
        'timeout' => env('FASTAPI_TIMEOUT', 30),
        'connect_timeout' => env('FASTAPI_CONNECT_TIMEOUT', 5),
        'webhooks' => explode(',', env('FASTAPI_WEBHOOKS', '')),
    ],

    'feeding_devices' => [
        'mqtt' => [
            'endpoint' => env('FEEDING_MQTT_ENDPOINT'),
            'topic' => env('FEEDING_MQTT_TOPIC', 'smarthog/feeders/commands'),
            'token' => env('FEEDING_MQTT_TOKEN'),
        ],
        'sinric' => [
            'endpoint' => env('FEEDING_SINRIC_ENDPOINT'),
            'token' => env('FEEDING_SINRIC_TOKEN'),
        ],
        'http' => [
            'endpoint' => env('FEEDING_HTTP_ENDPOINT'),
            'token' => env('FEEDING_HTTP_TOKEN'),
        ],
    ],

];
