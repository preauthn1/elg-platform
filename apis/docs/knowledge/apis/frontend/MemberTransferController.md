---
type: Api
id: apis/frontend/MemberTransferController
title: 會員遊戲商餘額轉帳
description: '會員 App 給會員使用，把餘額轉入遊戲商或轉回一般錢包，並查詢遊戲商餘額與轉帳狀態的入口。'
domain: 金流
tags:
  - MemberTransferController
  - 轉帳錢包
  - 遊戲商餘額
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Frontend/MemberTransferController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Frontend/MemberTransferController.php
  routes:
    - 'POST frontend/api/member/transfer'
    - 'POST frontend/api/member/transfer-all'
    - 'POST frontend/api/member/transfer-back-recent'
    - 'POST frontend/api/member/vendor-balance'
    - 'POST frontend/api/member/check-transfer-status'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Frontend\MemberTransferController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `frontend/api/member/transfer` | `transfer` | 查詢上一次轉入的遊戲商, 查詢餘額然後轉回本地主錢包 transferFromLastLog |  |
| POST | `frontend/api/member/transfer-all` | `transferAll` | 漸進式轉回本地錢包 |  |
| POST | `frontend/api/member/transfer-back-recent` | `transferBackRecent` | 轉回近三個月有轉入紀錄的遊戲商餘額 (V1活動用) |  |
| POST | `frontend/api/member/vendor-balance` | `getVendorBalance` | 查詢遊戲商餘額 |  |
| POST | `frontend/api/member/check-transfer-status` | `checkTransferStatus` | 查詢轉帳狀態 (測試用) |  |
<!-- knowledge:generated end -->

## 做什麼

會員在會員 App 把遊戲商的餘額轉回一般錢包，或查詢某個遊戲商的目前餘額與轉帳狀態。沒有遊戲錢包的會員，回應裡的遊戲錢包餘額顯示為 0，不會因此替會員新建一個遊戲錢包。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
