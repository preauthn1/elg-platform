---
type: Api
id: apis/external/EpicWinController
title: 'EpicWin 無縫錢包回調'
description: '遊戲商 EpicWin 的無縫錢包回調入口，處理查餘額、投注、派彩、取消投注、現金獎金與彩金。'
domain: 遊戲與遊戲商
tags:
  - EpicWinController
  - EpicWin
  - EpicSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/EpicWinController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/EpicWinController.php
  routes:
    - 'POST external/api/sw/epic/GetBalance'
    - 'POST external/api/sw/epic/Bet'
    - 'POST external/api/sw/epic/GameResult'
    - 'POST external/api/sw/epic/Rollback'
    - 'POST external/api/sw/epic/CashBonus'
    - 'POST external/api/sw/epic/Jackpot'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\EpicWinController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/epic/GetBalance` | `getBalance` |  |  |
| POST | `external/api/sw/epic/Bet` | `bet` |  |  |
| POST | `external/api/sw/epic/GameResult` | `settle` |  |  |
| POST | `external/api/sw/epic/Rollback` | `rollback` |  |  |
| POST | `external/api/sw/epic/CashBonus` | `bonus` |  |  |
| POST | `external/api/sw/epic/Jackpot` | `jackpot` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 EpicWin 的遊戲內查餘額、下注、局結束拿派彩、被取消投注，或拿到現金獎金、彩金時，由 EpicWin 呼叫這個入口；派彩回調帶的是整局最終結果而非增量，同一筆投注的結果被補送或調整時，我方以新舊結果的差額調整錢包。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 EpicWin；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-epicwin](../../rules/vendor-epicwin.md)
