---
type: Api
id: apis/external/AstarController
title: 'Astar 無縫錢包回調'
description: '提供真人與棋牌遊戲的遊戲商 Astar 回調入口，處理驗證玩家、查餘額、登出、上桌扣款、離桌入帳、取消投注、查細單與查未完成局。'
domain: 遊戲與遊戲商
tags:
  - AstarController
  - Astar
  - 真人
  - 棋牌
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AstarController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/AstarController.php
  routes:
    - 'POST external/api/sw/astar/gamepool/{gamehall}/player/auth'
    - 'GET|HEAD external/api/sw/astar/gamepool/{gamehall}/player/balance/{id}'
    - 'POST external/api/sw/astar/gamepool/{gamehall}/player/logout'
    - 'POST external/api/sw/astar/gamepool/{gamehall}/table/rollout'
    - 'POST external/api/sw/astar/gamepool/{gamehall}/table/rollin'
    - 'POST external/api/sw/astar/gamepool/{gamehall}/game/detailtoken'
    - 'GET|HEAD external/api/sw/astar/gamepool/{gamehall}/game/roundcheck'
    - 'POST external/api/sw/astar/gamepool/{gamehall}/game/bet/refund'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\AstarController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/astar/gamepool/{gamehall}/player/auth` | `authenticate` |  |  |
| GET|HEAD | `external/api/sw/astar/gamepool/{gamehall}/player/balance/{id}` | `balance` |  |  |
| POST | `external/api/sw/astar/gamepool/{gamehall}/player/logout` | `logout` |  |  |
| POST | `external/api/sw/astar/gamepool/{gamehall}/table/rollout` | `rollout` |  |  |
| POST | `external/api/sw/astar/gamepool/{gamehall}/table/rollin` | `rollin` |  |  |
| POST | `external/api/sw/astar/gamepool/{gamehall}/game/detailtoken` | `detailtoken` |  |  |
| GET|HEAD | `external/api/sw/astar/gamepool/{gamehall}/game/roundcheck` | `roundcheck` |  |  |
| POST | `external/api/sw/astar/gamepool/{gamehall}/game/bet/refund` | `refund` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 Astar（提供真人與棋牌遊戲）的遊戲桌上坐上或離開、或被取消投注時，由 Astar 呼叫對應端點；上桌時先扣走帶進桌的金額，離桌或一局結束時再依帶出金額入帳。Astar 另會呼叫驗證玩家、查餘額、登出、查細單與查未完成局等端點。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 Astar；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-astar](../../rules/vendor-astar.md)
