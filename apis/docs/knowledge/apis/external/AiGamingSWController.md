---
type: Api
id: apis/external/AiGamingSWController
title: 'AiGamingSW 無縫錢包回調'
description: '遊戲商 AiGamingSW 的無縫錢包回調入口，處理查餘額、投注扣款、結算、bonus game 派彩、取消投注與純派彩。'
domain: 遊戲與遊戲商
tags:
  - AiGamingSWController
  - AiGamingSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AiGamingSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/AiGamingSWController.php
  routes:
    - 'POST external/api/sw/aigaming/seamless/getBalance'
    - 'POST external/api/sw/aigaming/seamless/deduct'
    - 'POST external/api/sw/aigaming/seamless/settle'
    - 'POST external/api/sw/aigaming/seamless/bonus'
    - 'POST external/api/sw/aigaming/seamless/rollback'
    - 'POST external/api/sw/aigaming/seamless/cashBonus'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\AiGamingSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/aigaming/seamless/getBalance` | `getBalance` |  |  |
| POST | `external/api/sw/aigaming/seamless/deduct` | `deduct` |  |  |
| POST | `external/api/sw/aigaming/seamless/settle` | `settle` |  |  |
| POST | `external/api/sw/aigaming/seamless/bonus` | `bonus` |  |  |
| POST | `external/api/sw/aigaming/seamless/rollback` | `rollback` |  |  |
| POST | `external/api/sw/aigaming/seamless/cashBonus` | `cashBonus` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 AiGamingSW 的遊戲內查餘額、下注、結算、玩 bonus game（免費旋轉等遊戲內的獎勵遊戲）、被取消投注，或拿到純派彩（沒有對應投注的派彩）時，由 AiGamingSW 呼叫這個入口。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 AiGamingSW；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-aigamingsw](../../rules/vendor-aigamingsw.md)
