---
type: Api
id: apis/external/DepositNotifyController
title: 代收回調
description: 金流商在代收交易有結果後打回我方的回調入口，依結果判定充值訂單成功或失敗。
domain: 金流
tags:
  - DepositNotifyController
  - 代收
  - 回調
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/CashFlow/DepositNotifyController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/External/CashFlow/DepositNotifyController.php
  routes:
    - 'ANY external/api/deposit/notify/{agentCashFlowId?}'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\CashFlow\DepositNotifyController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| ANY | `external/api/deposit/notify/{agentCashFlowId?}` | `__invoke` | 三方代收代付回調 |  |
<!-- knowledge:generated end -->

## 做什麼

金流商在會員付款有結果後，呼叫這個入口通知平台。平台依網址上帶的金流商戶找出對應的充值訂單，通過來源 IP、金流商比對、簽名等共用檢查後，把訂單判定成功並上分，或判定失敗；沒通過檢查的回調不會動到任何訂單，只回失敗讓金流商視情況重送。規則見相關規則。

## 相關規則

* [rules/payment-callback-common-checks](../../rules/payment-callback-common-checks.md)
