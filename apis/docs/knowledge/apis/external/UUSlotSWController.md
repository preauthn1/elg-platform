---
type: Api
id: apis/external/UUSlotSWController
title: 'UUSlotSW 無縫錢包回調'
description: '遊戲商 UUSlotSW 的無縫錢包回調入口，處理查餘額、投注、派彩、取消投注、活動獎金與彩金。'
domain: 遊戲與遊戲商
tags:
  - UUSlotSWController
  - UUSlotSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/UUSlotSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/UUSlotSWController.php
  routes:
    - 'POST external/api/sw/uuslot/GetBalance'
    - 'POST external/api/sw/uuslot/Bet'
    - 'POST external/api/sw/uuslot/GameResult'
    - 'POST external/api/sw/uuslot/Rollback'
    - 'POST external/api/sw/uuslot/CashBonus'
    - 'POST external/api/sw/uuslot/Jackpot'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\UUSlotSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/uuslot/GetBalance` | `getBalance` |  |  |
| POST | `external/api/sw/uuslot/Bet` | `bet` |  |  |
| POST | `external/api/sw/uuslot/GameResult` | `gameResult` |  |  |
| POST | `external/api/sw/uuslot/Rollback` | `rollback` |  |  |
| POST | `external/api/sw/uuslot/CashBonus` | `cashBonus` |  |  |
| POST | `external/api/sw/uuslot/Jackpot` | `jackpot` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 UUSlotSW 的遊戲內查餘額、下注、拿派彩、被取消投注、領活動獎金或彩金時，由遊戲商呼叫這個入口。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給遊戲商；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-uuslotsw](../../rules/vendor-uuslotsw.md)
