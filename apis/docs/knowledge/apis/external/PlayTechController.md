---
type: Api
id: apis/external/PlayTechController
title: 'PTSW 無縫錢包回調'
description: '遊戲商 PlayTech 的無縫錢包版（PTSW，多為真人遊戲）回調入口，處理登入、查餘額、下注、派彩、取消下注、關局與紅利入帳。'
domain: 遊戲與遊戲商
tags:
  - PlayTechController
  - PTSW
  - PlayTech
  - 真人
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/PlayTechController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/PlayTechController.php
  routes:
    - 'POST external/api/sw/pt/auth'
    - 'POST external/api/sw/pt/authenticate'
    - 'POST external/api/sw/pt/bet'
    - 'POST external/api/sw/pt/settlement'
    - 'POST external/api/sw/pt/gameRoundResult'
    - 'POST external/api/sw/pt/balance'
    - 'POST external/api/sw/pt/getBalance'
    - 'POST external/api/sw/pt/logout'
    - 'POST external/api/sw/pt/keepAlive'
    - 'POST external/api/sw/pt/transfer'
    - 'POST external/api/sw/pt/transferFunds'
    - 'POST external/api/sw/pt/notifyBonusEvent'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\PlayTechController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/pt/auth` | `auth` |  |  |
| POST | `external/api/sw/pt/authenticate` | `auth` |  |  |
| POST | `external/api/sw/pt/bet` | `bet` |  |  |
| POST | `external/api/sw/pt/settlement` | `settlement` |  |  |
| POST | `external/api/sw/pt/gameRoundResult` | `settlement` |  |  |
| POST | `external/api/sw/pt/balance` | `balance` |  |  |
| POST | `external/api/sw/pt/getBalance` | `balance` |  |  |
| POST | `external/api/sw/pt/logout` | `logout` |  |  |
| POST | `external/api/sw/pt/keepAlive` | `keepAlive` |  |  |
| POST | `external/api/sw/pt/transfer` | `transferFunds` |  |  |
| POST | `external/api/sw/pt/transferFunds` | `transferFunds` |  |  |
| POST | `external/api/sw/pt/notifyBonusEvent` | `notifyBonusEvent` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 PlayTech 的無縫錢包版（PTSW，多為真人遊戲）登入、查餘額、下注、取得派彩，或遊戲商退款、關閉回合、發放紅利時，由 PlayTech 呼叫這個入口。同一局（gameRoundCode）允許多次下注，注單金額會累加；回調以同局鎖序列化併發請求、並以 deal record 做冪等，避免重送造成重複入帳。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 PlayTech；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-ptsw](../../rules/vendor-ptsw.md)
