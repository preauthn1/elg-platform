---
type: Api
id: apis/external/Ace333SWController
title: 'Ace333SW 無縫錢包回調'
description: '遊戲商 Ace333SW 的回調入口，處理驗證身分、查餘額、下注、派彩、彩金與取消投注。'
domain: 遊戲與遊戲商
tags:
  - Ace333SWController
  - Ace333SW
  - Ace333
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/Ace333SWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/Ace333SWController.php
  routes:
    - 'POST external/api/sw/ace333sw/authenticate'
    - 'POST external/api/sw/ace333sw/bet'
    - 'POST external/api/sw/ace333sw/betresult'
    - 'POST external/api/sw/ace333sw/jackpotwin'
    - 'GET|HEAD external/api/sw/ace333sw/getbalance'
    - 'POST external/api/sw/ace333sw/refund'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\Ace333SWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/ace333sw/authenticate` | `authenticate` |  |  |
| POST | `external/api/sw/ace333sw/bet` | `bet` |  |  |
| POST | `external/api/sw/ace333sw/betresult` | `settle` |  |  |
| POST | `external/api/sw/ace333sw/jackpotwin` | `jackpotWin` |  |  |
| GET|HEAD | `external/api/sw/ace333sw/getbalance` | `getBalance` |  |  |
| POST | `external/api/sw/ace333sw/refund` | `refund` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 Ace333SW 的遊戲內驗證身分、查餘額、下注、拿派彩或彩金、被取消投注時，由 Ace333SW 呼叫對應端點。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 Ace333SW；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-acewin](../../rules/vendor-acewin.md)
