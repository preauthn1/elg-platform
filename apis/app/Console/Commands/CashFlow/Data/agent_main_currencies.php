<?php

declare(strict_types=1);

/**
 * 代理主幣對照表（per-agent 細粒度覆寫）
 *
 * 職責：影響「代理層」資料寫入，需 agent_id 才能套用
 *
 * 用途：
 *   AddAgentCashFlow（單筆／by-channel 拆分模式皆同）建立 agent_cash_flows 時：
 *     1. 查 agent_main_currencies[conn][agent_id]
 *     2. 若為空字串，fallback 至 environments.{conn}.main_currency
 *     3. 兩者皆未設定，沿用 cash_flows.info[currency].rate
 *     4. 若解析出的代理主幣 === channel.currency，agent_cash_flows.rate 強制為 1
 *
 * ⚠️  與 site_environments.php 的分工：
 *   - environments.main_currency = 平台層（cash_flows.info、banks.type）—— 全環境共用
 *   - 本檔 agent_main_currency  = 代理層（agent_cash_flows.rate）—— per-agent 細粒度
 *   banks.type 為平台共用 row，無法 per-agent 翻轉，BANK ↔ FOREIGN_BANK 僅吃環境主幣。
 *
 * 結構：
 *   [ DB_CONNECTION 名稱 => [ agent_id => 'ISO code' ] ]
 *   空字串視為未設定，會 fallback 至環境主幣
 */

return [
    // 開發環境
    'mysql' => [
        4249 => 'MYR', // TP frankTEST
    ],

    // VWorld 環境
    'VWorld' => [
        49726 => 'MYR',
        53614 => 'MYR',
    ],

    // TPower 環境
    'TPower' => [
        71541 => 'MYR',
        71561 => 'MYR',
    ],

    // Me77 環境
    'Me77' => [
        100005 => 'MYR',
        100006 => 'MYR',
    ],

    // Yes2Win 環境
    'Yes2Win' => [
        100003 => 'MYR',
        100004 => 'MYR',
    ],

    // Alfawin77 環境
    'Alfawin77' => [
        100003 => 'IDR',
        100004 => 'IDR',
    ],

    // VPlus33 環境
    'VPlus33' => [
        100006 => 'MYR',
        100007 => 'MYR',
    ],
];
