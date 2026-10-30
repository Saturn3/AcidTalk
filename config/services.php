<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'resend' => ['key' => env('RESEND_KEY')],
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

    // Importazione da Zulip (php artisan acidtalk:import-zulip)
    'zulip' => [
        'url' => env('ZULIP_URL'),
        'email' => env('ZULIP_EMAIL'),
        'key' => env('ZULIP_API_KEY'),
    ],

    // Notifiche push app (Firebase Cloud Messaging HTTP v1): percorso del JSON dell'account
    // di servizio. In alternativa incollalo in Amministrazione › Notifiche push.
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],
];
