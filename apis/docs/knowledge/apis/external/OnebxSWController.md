---
type: Api
id: apis/external/OnebxSWController
title: 'OnebxSW 無縫錢包回調'
description: '體育遊戲商 OneBX 的無縫錢包版（OnebxSW）回調入口，處理查餘額、下注、派彩、取消派彩與取消下注。'
domain: 遊戲與遊戲商
tags:
  - OnebxSWController
  - OnebxSW
  - OneBX
  - 體育
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php
  routes:
    - 'POST external/api/sw/onebx/api/balance'
    - 'POST external/api/sw/onebx/api/bet'
    - 'POST external/api/sw/onebx/api/rollback'
    - 'POST external/api/sw/onebx/api/undo'
    - 'POST external/api/sw/onebx/api/settle'
    - 'POST external/api/sw/onebx/api/cancel'
    - 'POST external/api/sw/onebx/api/step/bet'
    - 'POST external/api/sw/onebx/api/step/rollback'
    - 'POST external/api/sw/onebx/api/step/undo'
    - 'POST external/api/sw/onebx/api/step/settle'
    - 'POST external/api/sw/onebx/api/step/cancel'
    - 'POST external/api/sw/onebx/api/step/bonus'
    - 'POST external/api/sw/onebx/api/step/refund-bonus'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\OnebxSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/onebx/api/balance` | `balance` |  |  |
| POST | `external/api/sw/onebx/api/bet` | `bet` |  |  |
| POST | `external/api/sw/onebx/api/rollback` | `rollback` |  |  |
| POST | `external/api/sw/onebx/api/undo` | `undo` |  |  |
| POST | `external/api/sw/onebx/api/settle` | `settle` |  |  |
| POST | `external/api/sw/onebx/api/cancel` | `cancel` |  |  |
| POST | `external/api/sw/onebx/api/step/bet` | `stepBet` |  |  |
| POST | `external/api/sw/onebx/api/step/rollback` | `stepRollback` |  |  |
| POST | `external/api/sw/onebx/api/step/undo` | `stepUndo` |  |  |
| POST | `external/api/sw/onebx/api/step/settle` | `stepSettle` |  |  |
| POST | `external/api/sw/onebx/api/step/cancel` | `stepCancel` |  |  |
| POST | `external/api/sw/onebx/api/step/bonus` | `stepBonus` |  |  |
| POST | `external/api/sw/onebx/api/step/refund-bonus` | `stepRefundBonus` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在體育遊戲商 OneBX 的無縫錢包版（OnebxSW）下注、取得派彩，或 OneBX 取消下注、取消派彩時，由 OneBX 呼叫這個入口；整個體育大廳在我方視為同一款遊戲。派彩、取消派彩、取消下注皆以批次送來，一次最多 30 筆、逐筆各自處理。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 OneBX；玩家餘額由我方保管。另有兩個 promotion（遊戲商自己的活動發放／回收）端點已掛路由但尚未實作，請求會被完整記錄後回系統錯誤，等實作上線後這些待處理的請求會自己補回來。規則見相關規則。

## 相關規則

* [rules/vendor-onebxsw](../../rules/vendor-onebxsw.md)
