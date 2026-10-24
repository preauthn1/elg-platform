<?php

declare(strict_types=1);

return [
    'site_name' => env('SITE_NAME', 'Unknown'),

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

    // 報表排除的遊戲商：逗號分隔 vendor_id，對齊 WEB_AgentBackend 的 SR11_NO_PRESELECTION（各站值不同，需同步）。
    // 代理／站點看板凡是聚合 summary_bet_records（投注、輸贏、盤費、投注人數、投注留存）一律不計這批遊戲商，
    // 使看板與 SR11 平台對帳表預設不勾選這批遊戲商時的數字一致（工單 4335）
    'report' => [
        'excluded_vendor_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SR11_NO_PRESELECTION', ''))))),
    ],

    // ! 辦公室
    'office' => [
        'ip' => env('OFFICE_IP'),
        'password' => env('OFFICE_PASSWORD'),
    ],

    // ! Olympus 後台 superadmin 初始帳號（SuperadminUserSeeder）
    'superadmin' => [
        'username' => env('SUPERADMIN_USERNAME'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

    'sms' => [
        'token_expired' => env('SMS_TOKEN_EXPIRED', 6),
        'max_send' => env('SMS_MAX_SEND', 3),
    ],

    // 驗證碼郵件：代理綁定的電子郵件設置組別沒有群組設定時的預設值（工單4286），不回退簡訊組別
    'mail' => [
        'token_expired' => env('MAIL_TOKEN_EXPIRED', 6),
        'max_send' => env('MAIL_MAX_SEND', 3),
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

    // 資料表結構變更監控（app/Facades/SchemaMonitor.php）
    'schema_monitor' => [
        /**
         * 免列數檢查的資料表白名單，僅對 SchemaMonitor::table() 的 ALTER 生效。
         *
         * 這裡放「本質上就會超過門檻、但改結構仍安全」的表：稽核／日誌類高頻寫入表，
         * 列數只會單向成長，若照一般流程走，正式站的 migration 會被列數檢查靜默跳過，
         * 卻仍被記為已執行 —— 欄位沒建成、程式端 unknown column 全面失敗，比鎖表更難查。
         *
         * 加表前必須確認：要下的 DDL 在 MySQL 8.0 可用 INSTANT 或 INPLACE + LOCK=NONE
         * 線上完成，不會退化成 COPY（鎖表重建）。無法確認者不要加，改走 member_codes
         * 那種手寫 DDL + 明確 ALGORITHM/LOCK 的方式（fail-safe，做不到就報錯）。
         */
        'row_count_exempt_tables' => [
            // 代理操作紀錄（稽核用），兩後端共寫，正式站列數遠超 50 萬且只增不減。
            'agent_operate_logs',
            // 會員提款單。2026-09-23 實查 TPower 161 萬、VWorld 145 萬、Yes2Win 58 萬，只增不減；
            // 三站 MySQL 8.0.37～8.0.41（Cloud SQL），ADD COLUMN ... AFTER 走 INSTANT（需 8.0.29+），不會退化成 COPY。
            // target/tickets#4353 加 withdraw_seq／tax_source 起適用。
            'withdrawal_orders',
        ],
    ],

    // 多幣別錢包（docs/plans/2026-08-13-multi-currency-wallet/）
    'multi_currency_wallet' => [
        /**
         * 雙寫開關。預設關閉——部署即零行為變化。
         *
         * 開啟後，members.balance 每次變動會額外派出影子同步 Job，把餘額同步進
         * member_wallets 的一般錢包（type=1）。真相仍在 members.balance，
         * 不做任何切換；用途是讓觀察期的每日校驗驗到真實寫入路徑。
         *
         * ⚠️ 開啟前置：第二期幣別主檔治理必須先完成（簡報第 9 節「第二期須先於
         * 第一期影子資料」）——幣別來源不可信時建出的錢包 currency_id 是猜的。
         * 進入第二期（切換寫入）前，此開關必須連續無差異一段時間並跑過兩次
         * 月結與分潤週期。
         */
        'dual_write' => env('MULTI_CURRENCY_WALLET_DUAL_WRITE', false),
    ],

    // 忘記密碼（重設密碼、取得帳號列表、檢核驗證碼）的頻率限制（工單 4540，取捨見 docs/adr/0005）
    // 誤擋時改 env 即可止血：max_attempts 設 0 等於關閉，不必回滾代碼
    'guest_password_reset_throttle' => [
        'max_attempts' => (int) env('GUEST_RESET_THROTTLE_MAX', 10), // 每把 key 在窗口內最多幾次，0＝關閉
        'decay_minutes' => (int) env('GUEST_RESET_THROTTLE_DECAY_MINUTES', 3), // 計數窗口（分鐘），超過次數擋到窗口結束
    ],

    // 請求頻率限制設置
    'rate_limit' => [
        'threshold' => env('RATE_LIMIT_THRESHOLD', 200), // 每分鐘請求閾值
        'redis_connection' => env('RATE_LIMIT_REDIS_CONNECTION', 'cache'), // Redis 連線名稱
        'whitelist' => [
            // FPay
            '0.0.0.0',
            // TNG Pin
            '0.0.0.0',
            '0.0.0.0',
        ],
    ],
];
