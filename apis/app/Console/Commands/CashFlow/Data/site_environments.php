<?php

declare(strict_types=1);

/**
 * 環境配置文件
 *
 * 根據資料庫連線配置不同環境的代理商 ID 與「平台層」主幣別
 *
 * 使用方式：
 * php artisan cash-flow:auto-sync --cashflow=79,80 --currency=3
 *
 * 欄位說明：
 * - main_currency:     **平台層**主幣 ISO code（'IDR' / 'MYR' / 'PHP' / 'VND' 等）
 *                      職責：影響跨代理共用的「平台層」資料寫入，與 agent_id 無關
 *                      影響 SyncCashFlowsCommand 對該環境的兩項正規化：
 *                        1. cash_flows.info[main_currency].rate 強制設為 1
 *                        2. withdrawal channel.type 自動翻轉 BANK ↔ FOREIGN_BANK
 *                      未設定（空字串）時不做任何翻轉。
 *
 *                      ⚠️  與 agent_main_currencies.php 的分工：
 *                        - 本檔的 main_currency = 平台層（banks、cash_flows）—— row 級設定，全環境共用
 *                        - agent_main_currencies = 代理層（agent_cash_flows.rate）—— per-agent 細粒度
 *                      同一環境下若代理主幣不同，banks.type 仍依本檔翻轉（取代表性主幣），
 *                      非主幣代理在前端顯示「本國銀行」需另闢邏輯（目前無解）。
 *
 * - production_agents: 正式代理，建立的 AgentCashFlow.status 預設為 0（停用），需手動審核後啟用
 * - test_agents:       測試代理，建立的 AgentCashFlow.status 預設為 1（啟用）
 */

return [
    // 開發環境
    'mysql' => [
        'main_currency' => 'MYR',
        'production_agents' => [],
        'test_agents' => [
            4249, // TP frankTEST
        ],
    ],

    // VWorld 環境
    'VWorld' => [
        'main_currency' => 'MYR',
        'production_agents' => [
            // 66829, // VWorldMY
            49726,  // VWorldSG
            // 59457,  // Wintoto
        ],
        'test_agents' => [
            // 37898, // VWorldMY
            53614, // VWorldSG
            // 59610, // Wintoto
        ],
    ],

    // TPower 環境
    'TPower' => [
        'main_currency' => 'MYR',
        'production_agents' => [
            71541,
        ],
        'test_agents' => [
            71561,
        ],
    ],

    // Me77 環境
    'Me77' => [
        'main_currency' => 'MYR',
        'production_agents' => [
            100005,
        ],
        'test_agents' => [
            100006,
        ],
    ],

    // Yes2Win 環境
    'Yes2Win' => [
        'main_currency' => 'MYR',
        'production_agents' => [
            100003,
        ],
        'test_agents' => [
            100004,
        ],
    ],

    // Alfawin77 環境
    'Alfawin77' => [
        'main_currency' => 'IDR',
        'production_agents' => [
            100003,
        ],
        'test_agents' => [
            100004,
        ],
    ],

    // VPlus33 環境
    'VPlus33' => [
        'main_currency' => 'MYR',
        'production_agents' => [
            100006,
        ],
        'test_agents' => [
            100007,
        ],
    ],
];
