---
type: Command
id: apis/console/order-recalc-payment-cost-summary
title: 重算金流商手續費彙總
description: 'RD 或維運在金流商手續費彙總算錯、或剛回補完訂單缺漏的手續費後執行，重建指定區間的充值與取款小時彙總。'
domain: 金流
tags:
  - order-recalc-payment-cost-summary
  - 金流商手續費
  - 彙總
  - 重算
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Order/RecalcPaymentCostSummary.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Order/RecalcPaymentCostSummary.php
  signature: 'order:recalc-payment-cost-summary'
---

<!-- knowledge:generated start -->
## 入口

`php artisan order:recalc-payment-cost-summary`

Command：`App\Console\Commands\Order\RecalcPaymentCostSummary`

重算 summary_event_records／summary_event_record_agents 的支付成本（event 3／8）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--from` | 選項（帶值） | 起日（+8 日曆日，YYYY-MM-DD，必填） |  |
| `--to` | 選項（帶值） | 迄日（+8 日曆日，含；預設今天） |  |
| `--agent` | 選項（帶值） | 只計此代理線以下的會員（Yes2Win 舊排程用 yes2win） | `Admin` |
| `--type` | 選項（帶值） | deposit＝只重算代收、withdrawal＝只重算代付、all＝兩者 | `all` |
| `--dry-run` | 選項（旗標） | 只列出區間與將刪除的列數，不寫入 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或維運在金流商手續費彙總算錯，或剛跑完回補命令需要讓彙總跟上訂單上的新值時執行這支命令。命令依指定的起訖日曆日（台北時間）換算成對應的 UTC 區間，對區間內的充值與取款彙總先刪除再依訂單上已寫定的金流商手續費重建，規則與每小時排程相同。可以只重算充值或只重算取款，也可以限定某個代理線以下的會員，預設兩者都算、範圍是全站。加 `--dry-run` 只會列出換算後的區間與即將刪除的筆數，不會真的刪除或寫入。規則見相關規則。

## 相關規則

* [rules/payment-cost-summary](../../rules/payment-cost-summary.md)
