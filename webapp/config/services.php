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

    // SendGrid: the SMTP login lives in the MAIL_* settings. `public_key` is the verification key of its signed Event Webhook. Both are
    // defaults: Platform > Settings > E-mail server replaces them.
    'sendgrid' => ['webhook_token' => env('SENDGRID_WEBHOOK_TOKEN'), 'public_key' => env('SENDGRID_WEBHOOK_PUBLIC_KEY')],

    // Twilio: the platform's own account for texts, used by every company that has not set up its own. Defaults: Platform > Settings > SMS replaces them.
    'twilio' => [
        'webhook_token' => env('TWILIO_WEBHOOK_TOKEN'),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Stripe (company subscriptions). Normally set under Platform > Settings > Payments; these are the fallback.
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
