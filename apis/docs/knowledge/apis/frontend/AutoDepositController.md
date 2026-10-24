---
type: Api
id: apis/frontend/AutoDepositController
title: 會員自助上分
description: '會員 App 給會員使用，把款項轉入平台公布的銀行帳戶後，建立待審核充值訂單的入口。'
domain: 金流
tags:
  - AutoDepositController
  - 自助上分
  - 充值
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Frontend/AutoDepositController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Frontend/AutoDepositController.php
  routes:
    - 'POST frontend/api/auto-deposit/bank-accounts'
    - 'POST frontend/api/auto-deposit/order/create'
    - 'POST frontend/api/auto-deposit/order/delete'
    - 'POST frontend/api/auto-deposit/order/upload-image'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Frontend\AutoDepositController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `frontend/api/auto-deposit/bank-accounts` | `bankAccounts` | 查詢銀行帳號列表 old:bank/getAccount |  |
| POST | `frontend/api/auto-deposit/order/create` | `createOrder` | 查詢/創建訂單 old:bank/order |  |
| POST | `frontend/api/auto-deposit/order/delete` | `deleteOrder` | 刪除訂單 old:bank/delete |  |
| POST | `frontend/api/auto-deposit/order/upload-image` | `uploadImage` | 上傳圖片 old:bank/uploadImage |  |
<!-- knowledge:generated end -->

## 做什麼

會員把錢轉到平台公布的銀行帳戶後，在會員 App 查詢可用的收款銀行帳號、建立一張待審核的充值訂單、上傳付款憑證圖片，或刪除還沒審核的訂單。建單當下就算好入帳點數與金流商手續費，會員不收手續費，等客服在代理後台審核通過後點數才加進錢包。規則見相關規則。

## 相關規則

* [rules/deposit-order-fees](../../rules/deposit-order-fees.md)
