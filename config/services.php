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

    // Public tenant self-registration (see docs/BILLING.md).
    'signup' => [
        // When set, registration requires this invite code (invite-only launch).
        'invite_code' => env('SIGNUP_INVITE_CODE'),
        'trial_days' => (int) env('SIGNUP_TRIAL_DAYS', 30),
    ],

    // ZIMRA FDMS fiscalisation (see docs/FISCALISATION.md and App\Services\Fdms\*).
    'fdms' => [
        // API hosts (device gateway) — distinct from the QR/portal hosts.
        'test_url' => env('FDMS_TEST_URL', 'https://fdmsapitest.zimra.co.zw'),
        'production_url' => env('FDMS_PRODUCTION_URL', 'https://fdmsapi.zimra.co.zw'),
        'test_qr_url' => env('FDMS_TEST_QR_URL', 'https://fdmstest.zimra.co.zw'),
        'production_qr_url' => env('FDMS_PRODUCTION_QR_URL', 'https://fdms.zimra.co.zw'),
        // Must match what was sent at registration byte-for-byte
        // (FDMS returns 403 otherwise).
        'model_name' => env('FDMS_MODEL_NAME', 'Server'),
        'model_version' => env('FDMS_MODEL_VERSION', 'v1'),
        // HS code fallback when neither the line nor the income category has one.
        'default_hs_code' => env('FDMS_DEFAULT_HS_CODE', '99001000'),
        'timezone' => env('FDMS_TIMEZONE', 'Africa/Harare'),
        'certificate_warning_days' => (int) env('FDMS_CERTIFICATE_WARNING_DAYS', 30),
        'pending_warning_minutes' => (int) env('FDMS_PENDING_WARNING_MINUTES', 15),
        'alert_email' => env('FDMS_ALERT_EMAIL'),
        'auto_close_enabled' => env('FDMS_AUTO_CLOSE_ENABLED', false),
        'auto_close_time' => env('FDMS_AUTO_CLOSE_TIME', '23:30'),
    ],

    // Daily exchange-rate auto-fetch (see App\Services\RateFetchService).
    // Primary is a stable commercial aggregator relaying the official
    // central-bank rate; the cross-check scrapes the RBZ published table.
    'exchange_rates' => [
        'primary_url' => env('FX_PRIMARY_URL', 'https://open.er-api.com/v6/latest'),
        'rbz_url' => env('FX_RBZ_URL', 'https://zwg-rates.vercel.app/api/rates'),
        // Max relative deviation between the two sources before the rate
        // is held back for manual entry instead of saved automatically.
        'tolerance' => (float) env('FX_TOLERANCE', 0.02),
        // Currencies to fetch daily, per company base currency.
        'currencies' => ['ZWG'],
    ],

];
