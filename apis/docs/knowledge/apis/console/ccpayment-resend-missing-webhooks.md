---
type: Command
id: apis/console/ccpayment-resend-missing-webhooks
title: '補送 CCPayment 遺漏的回調'
description: '排程每 30 分鐘執行，找出 CCPayment 已入帳或代付處理中過久卻沒有對應回調的訂單，請金流商重送。'
domain: 金流
tags:
  - ccpayment-resend-missing-webhooks
  - CCPayment
  - 回調補送
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php
  signature: 'ccpayment:resend-missing-webhooks'
  schedule:
    - '*/30 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan ccpayment:resend-missing-webhooks`

Command：`App\Console\Commands\CashFlow\CCPayment\ResendMissingWebhooksCommand`

CCPayment 回調補送：存款漏單與代付卡 22 超過 2 小時的單，請金流商重送 webhook，仍缺才告警

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--hours` | 選項（帶值） | 存款側回看的小時數，拉這段時間的存款紀錄比對 | `2` |
| `--dry-run` | 選項（旗標） | 只列出缺漏，不呼叫重送也不告警 |  |

排程：`*/30 * * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每 30 分鐘自動執行一次，比對 CCPayment 端已成功的入帳紀錄與平台的充值訂單，找出金流商已入帳、平台卻沒有對應充值訂單的款項，連同代付處理中狀態超過 2 小時的取款訂單，一併請金流商重送回調。重送過仍查不到的才發告警，不會重複重送；命令本身不建立訂單，也不直接改訂單狀態，入帳與代付結果一律交由回調定案。規則見相關規則。

## 相關規則

* [rules/provider-ccpayment](../../rules/provider-ccpayment.md)
