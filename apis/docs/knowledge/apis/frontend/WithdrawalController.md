---
type: Api
id: apis/frontend/WithdrawalController
title: 會員取款
description: '會員 App 給會員使用，查詢取款限制、綁定取款銀行帳號並送出取款申請的入口。'
domain: 金流
tags:
  - WithdrawalController
  - 取款
  - 會員手續費
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Frontend/WithdrawalController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Frontend/WithdrawalController.php
  routes:
    - 'POST frontend/api/withdrawal/orders'
    - 'POST frontend/api/withdrawal/flow-limit'
    - 'POST frontend/api/withdrawal/check-bank-account'
    - 'POST frontend/api/withdrawal/banks'
    - 'POST frontend/api/withdrawal/confirm-payment-password'
    - 'POST frontend/api/withdrawal/create-order'
    - 'POST frontend/api/withdrawal/bind-bank-account'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Frontend\WithdrawalController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `frontend/api/withdrawal/orders` | `getOrders` | 洗分紀錄 withdrawal/orders |  |
| POST | `frontend/api/withdrawal/flow-limit` | `getFlowLimit` | 稽核流水條件 (取得取款限制條件) flowLimit/show |  |
| POST | `frontend/api/withdrawal/check-bank-account` | `checkBankAccount` | 檢查是否有綁定銀行帳號 withdrawal/check |  |
| POST | `frontend/api/withdrawal/banks` | `getBanks` | 洗分銀行列表 withdrawal/account |  |
| POST | `frontend/api/withdrawal/confirm-payment-password` | `confirmPaymentPassword` | 核對會員支付密碼 checkPaymentPassword |  |
| POST | `frontend/api/withdrawal/create-order` | `createOrder` | 建立提款訂單 withdrawal |  |
| POST | `frontend/api/withdrawal/bind-bank-account` | `bindBankAccount` | 綁定銀行帳號 withdrawal/account |  |
<!-- knowledge:generated end -->

## 做什麼

會員在會員 App 填金額、選取款銀行後送出取款申請。系統依序做完建單前檢查並算好這筆的會員手續費，從會員錢包扣除取款金額，建立一張待審核的取款訂單，等客服或代付處理。這個入口也供會員查詢流水限制、綁定或檢查取款銀行帳號、核對支付密碼。規則見相關規則。

## 相關規則

* [rules/withdrawal-member-fee](../../rules/withdrawal-member-fee.md)
