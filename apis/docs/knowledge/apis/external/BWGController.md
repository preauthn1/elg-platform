---
type: Api
id: apis/external/BWGController
title: 'BWG 無縫錢包回調'
description: '經聚合商 target 接入的遊戲商 BWG 回調入口，與 target 共用同一套處理，涵蓋查餘額、下注、派彩、改派彩、取消投注與活動派彩。'
domain: 遊戲與遊戲商
tags:
  - BWGController
  - BWG
  - target
  - 聚合商
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/BWGController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/BWGController.php
  routes:
    - 'POST external/api/sw/bwg/players/wallet'
    - 'POST external/api/sw/bwg/bet/placeBet'
    - 'POST external/api/sw/bwg/bet/settle'
    - 'POST external/api/sw/bwg/bet/settle-without-payout'
    - 'POST external/api/sw/bwg/bet/settle-change'
    - 'POST external/api/sw/bwg/bonus/settle'
    - 'POST external/api/sw/bwg/bet/cancel'
    - 'POST external/api/sw/bwg/transaction/event-bonus'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\BWGController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/bwg/players/wallet` | `getBalance` |  |  |
| POST | `external/api/sw/bwg/bet/placeBet` | `placeBet` |  |  |
| POST | `external/api/sw/bwg/bet/settle` | `settle` |  |  |
| POST | `external/api/sw/bwg/bet/settle-without-payout` | `settleWithoutPayout` |  |  |
| POST | `external/api/sw/bwg/bet/settle-change` | `settleChange` |  |  |
| POST | `external/api/sw/bwg/bonus/settle` | `bonus` |  |  |
| POST | `external/api/sw/bwg/bet/cancel` | `cancel` |  |  |
| POST | `external/api/sw/bwg/transaction/event-bonus` | `eventBonus` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在經聚合商 target（把多家遊戲商包成同一套 API 轉給我方的中間商）接入的遊戲商 BWG 的遊戲內查餘額、下注、拿派彩、遊戲商改派彩、被取消投注，或拿到遊戲內獎勵、活動派彩時，由 BWG 呼叫這個入口；BWG 走自己的回調路徑，但行為與 target 相同，回調屬於哪一家遊戲商由請求 header 帶的身分代碼決定。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 BWG；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-target](../../rules/vendor-target.md)
