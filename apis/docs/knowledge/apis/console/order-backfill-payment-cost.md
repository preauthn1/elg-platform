---
type: Command
id: apis/console/order-backfill-payment-cost
title: 回補訂單的金流商手續費
description: 'RD 或維運在金流商手續費彙總與訂單對不上時執行，回補充值訂單與取款訂單上缺漏的金流商手續費。'
domain: 金流
tags:
  - order-backfill-payment-cost
  - 金流商手續費
  - 回補
  - 彙總
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Order/BackfillPaymentCost.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Order/BackfillPaymentCost.php
  signature: 'order:backfill-payment-cost'
---

<!-- knowledge:generated start -->
## 入口

`php artisan order:backfill-payment-cost`

Command：`App\Console\Commands\Order\BackfillPaymentCost`

回補 orders／withdrawal_orders 的 payment_cost（支付商手續費）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--months` | 選項（帶值） | 回看月數（依 created_at） | `6` |
| `--type` | 選項（帶值） | deposit＝只補代收、withdrawal＝只補代付、all＝兩者 | `all` |
| `--chunk` | 選項（帶值） | 每批 id 區間寬度 | `20000` |
| `--dry-run` | 選項（旗標） | 只列出各類待回補筆數，不寫入 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或維運在金流商手續費彙總與訂單對不上時執行這支命令，找出最近幾個月內金流商手續費仍是 0 的已上分充值訂單與已付款取款訂單，依當下的成本費率補上金流商手續費。已經有值的訂單不會被覆寫，所以可以重複執行；可以用 `--type` 限定只補充值或只補取款。回補只改訂單上的值，不會更新小時彙總，回補後還要接著跑重算命令，代理數據看板的數字才會跟上。規則見相關規則。

## 相關規則

* [rules/payment-cost-summary](../../rules/payment-cost-summary.md)
