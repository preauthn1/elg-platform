---
type: Command
id: apis/console/withdrawal-alert-stuck-submitted
title: 告警停留在代付提交中的取款訂單
description: 'Laravel 排程每 5 分鐘自動執行，查核停留在代付提交中超過門檻時間的取款訂單，能自動查單定案的先定案，查不清楚的才告警交人工。'
domain: 金流
tags:
  - withdrawal-alert-stuck-submitted
  - 代付
  - 告警
  - 查單
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/AlertStuckSubmittedWithdrawal.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Schedule/AlertStuckSubmittedWithdrawal.php
  signature: 'withdrawal:alert-stuck-submitted'
  schedule:
    - '*/5 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan withdrawal:alert-stuck-submitted`

Command：`App\Console\Commands\Schedule\AlertStuckSubmittedWithdrawal`

掃描停留於代付請求中(21)超過門檻的代付訂單，先經三方查單自動查證，未定案者告警

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--minutes` | 選項（帶值） | 停留門檻（分鐘），低於此時長視為正常在途 | `10` |

排程：`*/5 * * * *`
<!-- knowledge:generated end -->

## 做什麼

這支命令由 Laravel 排程每 5 分鐘自動執行，掃描停留在「代付提交中」(21) 超過門檻時間（預設 10 分鐘）的取款訂單。每一筆先嘗試向金流商查單自動查證，查到結果的訂單會自動補上單號轉為「代付處理中」(22)，不會再出現在告警裡。查無結果，或這家金流商不支援查單的訂單，系統發告警通知人工到金流商後台核對，命令本身不會改動這些訂單的狀態。規則見相關規則。

## 相關規則

* [rules/provider-a3pay](../../rules/provider-a3pay.md)
