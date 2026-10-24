---
type: Api
id: apis/external/Rcb988Controller
title: 'RCB988 無縫錢包回調'
description: '遊戲商 RCB988 的回調入口，處理查餘額、下注、調整下注、結算、取消與還原取消。'
domain: 遊戲與遊戲商
tags:
  - Rcb988Controller
  - RCB988
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/Rcb988Controller.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/Rcb988Controller.php
  routes:
    - 'POST external/api/sw/rcb988'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\Rcb988Controller`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/rcb988` | `entrance` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 RCB988 的遊戲內查餘額、下注、調整下注、結算、取消或還原取消時，由 RCB988 呼叫同一個入口，依請求內的動作名稱分派；一次回調可以帶多筆交易，整批在同一個資料庫交易內處理，任一筆失敗則整批不生效。平台依回調內容增減會員錢包餘額、寫入注單，並把最新餘額回給 RCB988；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-rcb988](../../rules/vendor-rcb988.md)
