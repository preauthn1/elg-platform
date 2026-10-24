---
type: Command
id: apis/console/summary-payment-cost
title: 每小時彙總金流商手續費
description: 'Laravel 排程每小時自動執行，把最近幾個整點小時訂單上已寫定的金流商手續費重新彙總，供代理數據看板讀取。'
domain: 金流
tags:
  - summary-payment-cost
  - 金流商手續費
  - 彙總
  - 排程
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Order/SummarizePaymentCost.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Order/SummarizePaymentCost.php
  signature: 'summary:payment-cost'
  schedule:
    - '20 * * * * --hours=3'
---

<!-- knowledge:generated start -->
## 入口

`php artisan summary:payment-cost`

Command：`App\Console\Commands\Order\SummarizePaymentCost`

每小時重建最近 N 小時的支付成本彙總（summary_event_records event 3／8）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--hours` | 選項（帶值） | 回看的整點小時數（含剛結束的那一小時） | `3` |
| `--agent` | 選項（帶值） | 只計此代理線以下的會員（預設全站） | `Admin` |

排程：`20 * * * * --hours=3`
<!-- knowledge:generated end -->

## 做什麼

這支命令由 Laravel 排程每小時 20 分自動執行，不必人工操作，同一時間只在一台伺服器上跑。命令重建最近幾個整點小時（預設 3 小時）的金流商手續費彙總，充值依上分時間、取款依付款時間歸到所屬小時，直接加總訂單上已經寫定的金流商手續費，不重新套用成本費率。重建方式是先刪除範圍內的舊彙總再重寫、同一區間重跑結果相同；代理數據看板的成本區讀的就是這份彙總，不是訂單本身。規則見相關規則。

## 相關規則

* [rules/payment-cost-summary](../../rules/payment-cost-summary.md)
