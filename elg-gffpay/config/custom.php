<?php

return [
    'frontend' => [
        'timezone' => env('FRONTEND_TIMEZONE', 'UTC'),
        'token_expired' => env('FRONTEND_TOKEN_EXPIRED', 1440),
    ],
    'backend' => [
        'timezone' => env('BACKEND_TIMEZONE', 'UTC'),
    ],

    // 主要幣別
    'currency_id' => env('CURRENCY_ID', 1),

    // ! 網址
    'api_external_url' => env('API_EXTERNAL_URL', ''),
    'api_cs_url' => env('API_CS_URL', ''),
    'web_frontend_url' => env('WEB_FRONTEND_URL', ''),

    // 無限代前綴
    'unlimit_prefix' => env('UNLIMIT_PREFIX', 'UA'),
    'event_code_prefix' => env('EVENT_CODE_PREFIX', 'IE'),
    'pgs_layout_lobby_agent' => env('PGS_LAYOUT_LOBBY_AGENT', '9999999'),

    // ! 辦公室
    'office' => [
        'ip' => env('OFFICE_IP'),
        'password' => env('OFFICE_PASSWORD'),
    ],

    'sms' => [
        'token_expired' => env('SMS_TOKEN_EXPIRED', 6),
        'max_send' => env('SMS_MAX_SEND', 3),
    ],

    // 在 app/Http/Middleware/RequestLogger.php 紀錄請求日誌
    'log_request' => env('SWITCH_LOG_REQUEST', true),

    'log_sql' => env('SWITCH_LOG_SQL', false),

    // 告警設置
    'alarm' => [
        'query_count' => env('ALARM_QUERY_COUNT', 30), // 查詢次數過高
        'query_duration' => env('ALARM_QUERY_DURATION', 3), // 查詢時間過長
        'max_sql_logs' => env('ALARM_MAX_SQL_LOGS', 50), // 最多紀錄幾條SQL語句
    ],
];
