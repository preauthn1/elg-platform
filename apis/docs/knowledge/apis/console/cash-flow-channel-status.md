---
type: Command
id: apis/console/cash-flow-channel-status
title: 查詢與調整支付通道上下架狀態
description: 'RD 或上線人員要查某個金流商底下所有金流商戶與支付通道的上下架狀態，或要調整其中一項的上下架與後台顯示時使用。'
domain: 金流
tags:
  - cash-flow-channel-status
  - 上下架
  - 後台顯示
  - 支付通道
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/ChannelStatus.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/ChannelStatus.php
  signature: 'cash-flow:channel-status'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:channel-status`

Command：`App\Console\Commands\CashFlow\ChannelStatus`

以 cash_flows.id 為根查／改渠道（ACF）與支付方式（ACFOT）上下架 status 與後台顯示

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `cash_flow_id` | 引數（必填） | 目標金流商 cash_flows.id |  |
| `--acf` | 選項（帶值） | 要改 status 的 agent_cash_flows.id |  |
| `--acfot` | 選項（帶值） | 要改 status 的 agent_cash_flow_order_types.id |  |
| `--set-status` | 選項（帶值） | 目標 status（0=停用 1=啟用） |  |
| `--backend-display` | 選項（帶值） | 目標 backend_display（0=後台隱藏 1=顯示），選用，不帶就不動 |  |
| `--force` | 選項（旗標） | 跳過執行前確認 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或上線人員只帶金流商編號執行這支命令時，會以樹狀列出該金流商底下每個金流商戶與支付通道目前的上下架狀態與後台顯示設定，不做任何變更。另外指定要改的金流商戶或支付通道、以及目標的上下架狀態或後台顯示值時，命令只改有變動的欄位，寫入前會先列出改動前後的值，經確認才寫入。上下架與後台顯示只接受 0 或 1，指定的對象不存在或不屬於該金流商時會被拒絕。規則見相關規則。

## 相關規則

* [rules/payment-channel-management](../../rules/payment-channel-management.md)
