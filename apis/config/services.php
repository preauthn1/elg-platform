<?php

declare(strict_types=1);

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     | Olympus 後台 Office 365 SSO（Entra ID）。
     | redirect 優先取 AZURE_REDIRECT_URI（k8s 環境 APP_URL 為叢集內部位址、且後台實際
     | 瀏覽網域為 tools-*，必須明確指定）；未設時由 APP_URL 推導（本機開發用）。
     | redirect 必須與 Entra 端登記的 URI 逐字元相同，且與使用者瀏覽的網域同 host
     |（否則 callback 拿不到 session state）。tenant 必填 tenant ID，禁止填 common。
     */
    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI') ?: rtrim((string) env('APP_URL'), '/') . '/olympus/auth/callback',
        'tenant' => env('AZURE_TENANT'),
    ],

];
