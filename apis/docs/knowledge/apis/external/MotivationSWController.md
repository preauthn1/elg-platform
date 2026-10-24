---
type: Api
id: apis/external/MotivationSWController
title: 'MotivationSW 無縫錢包回調'
description: '遊戲商 MotivationSW（對外品牌 Gameboy）的無縫錢包回調入口，處理查詢、下注、退款、結算與事後扣補款。'
domain: 遊戲與遊戲商
tags:
  - MotivationSWController
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/MotivationSWController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/External/SingleWallets/MotivationSWController.php
  routes:
    - 'GET|HEAD external/api/sw/motivation/player/check/{account}'
    - 'GET|HEAD external/api/sw/motivation/transaction/balance/{account}'
    - 'POST external/api/sw/motivation/transaction/game/bet'
    - 'POST external/api/sw/motivation/transaction/game/refund'
    - 'POST external/api/sw/motivation/transaction/game/endround'
    - 'POST external/api/sw/motivation/transaction/game/rollout'
    - 'POST external/api/sw/motivation/transaction/game/rollin'
    - 'POST external/api/sw/motivation/transaction/game/debit'
    - 'POST external/api/sw/motivation/transaction/game/credit'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\MotivationSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| GET|HEAD | `external/api/sw/motivation/player/check/{account}` | `checkPlayer` |  |  |
| GET|HEAD | `external/api/sw/motivation/transaction/balance/{account}` | `balance` |  |  |
| POST | `external/api/sw/motivation/transaction/game/bet` | `bet` |  |  |
| POST | `external/api/sw/motivation/transaction/game/refund` | `refund` |  |  |
| POST | `external/api/sw/motivation/transaction/game/endround` | `endround` |  |  |
| POST | `external/api/sw/motivation/transaction/game/rollout` | `bet` |  |  |
| POST | `external/api/sw/motivation/transaction/game/rollin` | `rollin` |  |  |
| POST | `external/api/sw/motivation/transaction/game/debit` | `debit` |  |  |
| POST | `external/api/sw/motivation/transaction/game/credit` | `credit` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 MotivationSW（對外品牌 Gameboy）的遊戲內查詢、下注、轉出、退款、結算、轉回或遭遇事後扣補款時，由遊戲商呼叫這個入口。平台依回調內容增減會員錢包餘額、寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把最新餘額回給遊戲商；玩家餘額由我方保管，遊戲商不持有餘額。規則見相關規則。

## 相關規則

* [rules/vendor-motivationsw](../../rules/vendor-motivationsw.md)
