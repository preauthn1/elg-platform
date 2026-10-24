---
type: Api
id: apis/external/AcewinController
title: 'Acewin 無縫錢包回調'
description: '遊戲商 Acewin（程式識別名 AcewinSW）的無縫錢包回調入口，處理驗證身分、下注（下注與派彩合一）與取消投注。'
domain: 遊戲與遊戲商
tags:
  - AcewinController
  - Acewin
  - AcewinSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AcewinController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/AcewinController.php
  routes:
    - 'POST external/api/sw/acewin/auth'
    - 'POST external/api/sw/acewin/bet'
    - 'POST external/api/sw/acewin/cancelBet'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\AcewinController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/acewin/auth` | `auth` |  |  |
| POST | `external/api/sw/acewin/bet` | `bet` |  |  |
| POST | `external/api/sw/acewin/cancelBet` | `cancelBet` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 Acewin（程式識別名 AcewinSW）的遊戲內下注，或 Acewin 取消投注時，由 Acewin 呼叫對應端點；Acewin 的下注回調同時帶投注額與派彩額，一筆回調就完成下注與派彩。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 Acewin；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-acewin](../../rules/vendor-acewin.md)
