---
type: Command
id: apis/console/withdrawal-resolve-payout
title: 代付停留單人工定案
description: 'RD 或維運在金流商後台人工核對出取款訂單的實際出款結果後執行，把停留的代付訂單定案為可重新發起或代付成功。'
domain: 金流
tags:
  - withdrawal-resolve-payout
  - 代付
  - 人工定案
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Order/ResolvePayoutOrder.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Order/ResolvePayoutOrder.php
  signature: 'withdrawal:resolve-payout'
---

<!-- knowledge:generated start -->
## 入口

`php artisan withdrawal:resolve-payout`

Command：`App\Console\Commands\Order\ResolvePayoutOrder`

代付停留單人工定案（21/22）：解鎖重送(29)或定案成功(24)

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `order_id` | 引數（必填） | 提款訂單 ID |  |
| `--outcome` | 選項（帶值） | 人工核對結果：resend（支付商查無此單，解鎖為 29 可重送）或 success（支付商已出款，定案 24） |  |
| `--dry-run` | 選項（旗標） | 只顯示將執行的動作，不寫入 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或維運在金流商後台人工核對出某張取款訂單的實際出款結果後，執行這支命令替訂單定案。命令只處理「代付提交中」(21) 與「代付處理中」(22) 的訂單，其他狀態一律拒絕執行、訂單不變。依人工核對的結果，可以把訂單定為「三方失敗」(29) 解鎖重新發起代付，或定為「三方成功」(24) 並依金流商手續費的算法寫入該筆金額。加 `--dry-run` 只會顯示即將執行的動作，不會真的寫入。規則見相關規則。

## 相關規則

* [rules/payment-cost-summary](../../rules/payment-cost-summary.md)
* [rules/payout-submit-and-status](../../rules/payout-submit-and-status.md)
