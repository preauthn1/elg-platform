---
type: Api
id: apis/external/PegasusController
title: 'target 無縫錢包回調'
description: '聚合商 target（Pegasus，又稱 PGSSW）的回調入口，處理查餘額、下注、派彩、改派彩、取消下注與活動派彩；BWG 與 TIGER9 經由 target 接入，共用同一套規則。'
domain: 遊戲與遊戲商
tags:
  - PegasusController
  - target
  - PGSSW
  - Pegasus
  - 聚合商
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/PegasusController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/PegasusController.php
  routes:
    - 'POST external/api/sw/target/players/wallet'
    - 'POST external/api/sw/target/bet/placeBet'
    - 'POST external/api/sw/target/bet/settle'
    - 'POST external/api/sw/target/bonus/settle'
    - 'POST external/api/sw/target/bet/settle-without-payout'
    - 'POST external/api/sw/target/bet/settle-change'
    - 'POST external/api/sw/target/bet/cancel'
    - 'POST external/api/sw/target/transaction/event-bonus'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\PegasusController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/target/players/wallet` | `getBalance` |  |  |
| POST | `external/api/sw/target/bet/placeBet` | `placeBet` |  |  |
| POST | `external/api/sw/target/bet/settle` | `settle` |  |  |
| POST | `external/api/sw/target/bonus/settle` | `bonus` |  |  |
| POST | `external/api/sw/target/bet/settle-without-payout` | `settleWithoutPayout` |  |  |
| POST | `external/api/sw/target/bet/settle-change` | `settleChange` |  |  |
| POST | `external/api/sw/target/bet/cancel` | `cancel` |  |  |
| POST | `external/api/sw/target/transaction/event-bonus` | `eventBonus` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在聚合商 target（含經由它接入的 BWG 與 TIGER9）的遊戲內查餘額、下注、取得派彩、遊戲商改派彩、取消下注，或收到遊戲內獎勵派彩與活動派彩時，由對應的遊戲商呼叫這個入口；回調屬於哪一家遊戲商，由請求 header 帶的身分代碼對到的遊戲商戶決定，不看路徑。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給遊戲商；玩家餘額由我方保管。已停用的「派彩不異動餘額」端點仍保留供舊請求相容，純粹記錄注單、不再異動餘額。規則見相關規則。

## 相關規則

* [rules/vendor-target](../../rules/vendor-target.md)
