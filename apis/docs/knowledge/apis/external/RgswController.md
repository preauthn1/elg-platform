---
type: Api
id: apis/external/RgswController
title: 'RGSW 無縫錢包回調'
description: '遊戲商 RG 的無縫錢包版（RGSW）回調入口，處理查玩家、查餘額、下注、派彩、退款與事後調整類回調。'
domain: 遊戲與遊戲商
tags:
  - RgswController
  - RGSW
  - RG
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/RgswController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/RgswController.php
  routes:
    - 'GET|HEAD external/api/sw/rg/user/check/{username}'
    - 'GET|HEAD external/api/sw/rg/user/balance/{username}'
    - 'POST external/api/sw/rg/game/bet'
    - 'POST external/api/sw/rg/game/payout'
    - 'POST external/api/sw/rg/game/endRound'
    - 'POST external/api/sw/rg/game/betPayout'
    - 'POST external/api/sw/rg/game/refund'
    - 'POST external/api/sw/rg/game/debit'
    - 'POST external/api/sw/rg/game/credit'
    - 'POST external/api/sw/rg/game/sumBet'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\RgswController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| GET|HEAD | `external/api/sw/rg/user/check/{username}` | `checkPlayer` |  |  |
| GET|HEAD | `external/api/sw/rg/user/balance/{username}` | `balance` |  |  |
| POST | `external/api/sw/rg/game/bet` | `bet` |  |  |
| POST | `external/api/sw/rg/game/payout` | `payout` |  |  |
| POST | `external/api/sw/rg/game/endRound` | `endRound` |  |  |
| POST | `external/api/sw/rg/game/betPayout` | `betPayout` |  |  |
| POST | `external/api/sw/rg/game/refund` | `refund` |  |  |
| POST | `external/api/sw/rg/game/debit` | `debit` |  |  |
| POST | `external/api/sw/rg/game/credit` | `credit` |  |  |
| POST | `external/api/sw/rg/game/sumBet` | `sumBet` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 RG 的無縫錢包版（RGSW）查玩家、查餘額、下注、取得派彩，或 RG 事後退款、扣款、補款、追加下注時，由 RG 呼叫對應端點。同一局在我方只有一張注單，派彩與各種事後調整都直接更新這張注單，並把每次請求記在注單上；調整類回調沒有交易序號可判斷重送，改以同一局、同一種調整、同一金額且剛套用過來認定重複，重複時錢包不再變動，直接回目前餘額。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 RG；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-rgsw](../../rules/vendor-rgsw.md)
