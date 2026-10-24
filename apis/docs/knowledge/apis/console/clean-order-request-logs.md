---
type: Command
id: apis/console/clean-order-request-logs
title: 清理逾期的三方往返紀錄
description: '排程每天執行，刪除超過 90 天的金流回調往返紀錄。'
domain: 金流
tags:
  - clean-order-request-logs
  - 三方往返紀錄
  - 回調
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/CleanOrderRequestLogs.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Schedule/CleanOrderRequestLogs.php
  signature: 'clean:order-request-logs'
  schedule:
    - '40 5 * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan clean:order-request-logs`

Command：`App\Console\Commands\Schedule\CleanOrderRequestLogs`

清理 90 天前的三方往返紀錄（order_request_logs）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--dry-run` | 選項（旗標） | 只顯示將刪除的列數，不實際刪除 |  |

排程：`40 5 * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每天 05:40 自動執行，刪除 90 天前的三方往返紀錄。這些紀錄記下金流商每一次回調的時間、判定結果、三方單號與來源 IP，供代理後台的充值訂單與取款訂單詳細資料頁顯示往返過程。刪除只縮短查得到的歷史長度，不影響訂單本身的狀態與金額。規則見相關規則。

## 相關規則

* [rules/payment-callback-common-checks](../../rules/payment-callback-common-checks.md)
