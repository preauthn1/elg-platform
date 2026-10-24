<?php

declare(strict_types=1);

return [
    'default' => env('BOT_DRIVER', 'telegram'),

    'driver' => [
        'telegram' => [
            'token' => env('BOT_TELEGRAM_TOKEN'),
        ],
    ],

    // 🔴 頻道鍵與 env chat id 必須成對新增。BotNotify::channel() 傳的名字不在本陣列裡、
    // 又未另行指定 chat id 時，解析成空字串，BotNotifyService::telegram() 直接 return
    // ——不拋例外、呼叫端無從察覺。
    // 字面頻道名有 BotNotifyChannelKeysTest 掃 app/ 擋著，但它只驗這裡有沒有鍵、
    // 不驗 env chat id 有沒有配——只加本陣列這半邊，測試會過而通知依然不發。
    'channel' => [
        'debug' => env('BOT_DEBUG_CHAT_ID'),
        'info' => env('BOT_INFO_CHAT_ID'),
        'error' => env('BOT_ERROR_CHAT_ID'),
        'warn' => env('BOT_WARN_CHAT_ID'),
        'large_amount' => env('LARGE_AMOUNT_CHAT_ID'),
    ],

    // 大額提款通知
    'large_amount' => [
        'watch_agents' => env('LARGE_AMOUNT_WATCH_AGENTS', ''),
        'threshold' => (int) env('LARGE_AMOUNT_THRESHOLD', 5000),
    ],
];
