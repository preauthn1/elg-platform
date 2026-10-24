---
type: Api
id: apis/frontend/DepositController
title: 會員充值
description: '會員 App 給會員使用，查詢支付通道、建立充值訂單，並取得 CCPayment 固定收款地址的入口。'
domain: 金流
tags:
  - DepositController
  - 充值
  - 代收
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Frontend/DepositController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Frontend/DepositController.php
  routes:
    - 'POST frontend/api/deposit/types'
    - 'POST frontend/api/deposit/top-up-types'
    - 'POST frontend/api/deposit/top-up-channels'
    - 'POST frontend/api/deposit/orders'
    - 'POST frontend/api/deposit/create-order'
    - 'POST frontend/api/deposit/crypto-address'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Frontend\DepositController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `frontend/api/deposit/types` | `depositTypesGroup` | ! VPlus33 充值分組整合渠道取代原本兩隻接口 |  |
| POST | `frontend/api/deposit/top-up-types` | `topUpTypes` | 快速充值 (獲取所有支付方式的列表) deposit/getOrderType |  |
| POST | `frontend/api/deposit/top-up-channels` | `topUpChannels` | 快速充值渠道 deposit/getChildOrderType |  |
| POST | `frontend/api/deposit/orders` | `orders` | 充值訂單列表 orders |  |
| POST | `frontend/api/deposit/create-order` | `createOrder` | 提交充值訂單 deposit/order |  |
| POST | `frontend/api/deposit/crypto-address` | `cryptoAddress` | 固定收款地址（永久地址模式）：會員點選 BIND_CRYPTO_WALLET 的支付通道時取得 |  |
<!-- knowledge:generated end -->

## 做什麼

會員在會員 App 的充值頁查詢可用的支付通道與充值訂單列表，並送出充值訂單；送出後要到金流商付款，金流商回調確認成功後點數才加進錢包。選用 CCPayment 的支付通道時不走填金額建單，改為取得一個固定的鏈上收款地址，款項到鏈上後由回調觸發建單與上分。規則見相關規則。

## 相關規則

* [rules/provider-ccpayment](../../rules/provider-ccpayment.md)
