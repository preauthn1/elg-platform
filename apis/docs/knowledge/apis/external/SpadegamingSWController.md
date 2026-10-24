---
type: Api
id: apis/external/SpadegamingSWController
title: 'SpadegamingSW 無縫錢包回調'
description: '遊戲商 SpadegamingSW（Spadegaming 的無縫錢包版）的回調入口，處理查餘額、下注、取消下注、派彩與紅利。'
domain: 遊戲與遊戲商
tags:
  - SpadegamingSWController
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/SpadegamingSWController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/External/SingleWallets/SpadegamingSWController.php
  routes:
    - 'POST external/api/sw/spadegaming'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\SpadegamingSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/spadegaming` | `entrance` | Spadegaming APIs |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 SpadegamingSW（Spadegaming 的無縫錢包版）的遊戲內查餘額、下注、取消下注、拿到派彩或紅利時，由遊戲商呼叫這個入口。平台依回調內容增減會員錢包餘額、寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把最新餘額回給遊戲商；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-spadegamingsw](../../rules/vendor-spadegamingsw.md)
