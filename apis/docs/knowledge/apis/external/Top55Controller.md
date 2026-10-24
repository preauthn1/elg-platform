---
type: Api
id: apis/external/Top55Controller
title: 'Top55SW 無縫錢包回調'
description: '聚合商 Top55SW 的無縫錢包回調入口，處理查餘額、單筆交易與批次交易。'
domain: 遊戲與遊戲商
tags:
  - Top55Controller
  - Top55SW
  - 聚合商
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/Top55Controller.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/Top55Controller.php
  routes:
    - 'POST external/api/sw/top55/api/balance'
    - 'POST external/api/sw/top55/api/transaction'
    - 'POST external/api/sw/top55/api/batch-transactions'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\Top55Controller`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/top55/api/balance` | `balance` |  |  |
| POST | `external/api/sw/top55/api/transaction` | `transaction` |  |  |
| POST | `external/api/sw/top55/api/batch-transactions` | `batchTransactions` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在聚合商 Top55SW（把多家遊戲商包成同一套 API 轉給我方的中間商，旗下 31 家遊戲商在我方各算一個遊戲商）旗下的遊戲內查餘額、投注、拿派彩或被取消投注時，由 Top55SW 呼叫這個入口；單筆交易以金額正負分辨投注與派彩，批次交易則一次送多筆。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 Top55SW；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-top55](../../rules/vendor-top55.md)
