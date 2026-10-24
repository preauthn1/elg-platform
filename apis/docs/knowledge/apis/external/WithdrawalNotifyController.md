---
type: Api
id: apis/external/WithdrawalNotifyController
title: 代付回調
description: 金流商在代付交易有結果後打回我方的回調入口，依結果判定取款訂單的代付請求成功或失敗。
domain: 金流
tags:
  - WithdrawalNotifyController
  - 代付
  - 回調
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/CashFlow/WithdrawalNotifyController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/External/CashFlow/WithdrawalNotifyController.php
  routes:
    - 'ANY external/api/withdrawal/notify/{agentCashFlowId?}'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\CashFlow\WithdrawalNotifyController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| ANY | `external/api/withdrawal/notify/{agentCashFlowId?}` | `__invoke` |  |  |
<!-- knowledge:generated end -->

## 做什麼

金流商在平台出款有結果後，呼叫這個入口通知平台。平台依網址上帶的金流商戶找出對應的代付請求，通過來源 IP、金流商比對等共用檢查後，把代付請求判定成功或失敗；沒通過檢查的回調不會動到任何訂單，只回失敗讓金流商視情況重送。規則見相關規則。

## 相關規則

* [rules/payment-callback-common-checks](../../rules/payment-callback-common-checks.md)
