---
type: Api
id: apis/frontend/WalletController
title: 會員錢包紀錄與互轉
description: '會員 App 給會員使用，查詢一般錢包與遊戲錢包的帳變紀錄，並在兩者之間互轉餘額的入口。'
domain: 金流
tags:
  - WalletController
  - 帳變流水
  - 錢包互轉
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Frontend/WalletController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Frontend/WalletController.php
  routes:
    - 'POST frontend/api/wallet/main-logs'
    - 'POST frontend/api/wallet/game-logs'
    - 'POST frontend/api/wallet/histories'
    - 'POST frontend/api/wallet/transfer'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Frontend\WalletController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `frontend/api/wallet/main-logs` | `mainWalletLogs` | 主錢包紀錄 mainWalletLog |  |
| POST | `frontend/api/wallet/game-logs` | `gameWalletLogs` | 遊戲錢包紀錄 gameWalletLog |  |
| POST | `frontend/api/wallet/histories` | `walletHistories` | (新) 錢包紀錄, 透過 wallet_type 切換主錢包或遊戲錢包紀錄 |  |
| POST | `frontend/api/wallet/transfer` | `walletTransfer` | 主錢包轉帳至第二錢包 transferGameWallet |  |
<!-- knowledge:generated end -->

## 做什麼

會員在會員 App 查詢一般錢包或遊戲錢包的帳變流水，也可以把一般錢包的餘額轉入遊戲錢包。VWorld 會員在一般錢包與遊戲錢包之間互轉時，若還沒有既有的遊戲錢包會被擋下，不會因此替會員新建一個。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
