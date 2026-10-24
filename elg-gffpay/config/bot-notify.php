<?php

return [
    'default' => env('BOT_DRIVER', 'telegram'),

    'driver' => [
        'telegram' => [
            'token' => env('BOT_TELEGRAM_TOKEN'),
        ],
    ],

    'channel' => [
        'debug' => env('BOT_DEBUG_CHAT_ID'),
        'info' => env('BOT_INFO_CHAT_ID'),
        'error' => env('BOT_ERROR_CHAT_ID'),
        'warn' => env('BOT_WARN_CHAT_ID'),
    ],

    // 大額提款通知
    'large_amount' => [
        'notify_channel' => env('LARGE_AMOUNT_CHAT_ID'),
        'watch_agents' => env('LARGE_AMOUNT_WATCH_AGENTS', ''),
    ],
];
