---
type: Api
id: apis/external/WudiSWController
title: 'WudiSW 無縫錢包回調'
description: '遊戲商 WudiSW 的無縫錢包回調入口，處理查餘額與投注、派彩、取消投注、獎勵四種轉帳類型。'
domain: 遊戲與遊戲商
tags:
  - WudiSWController
  - WudiSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/WudiSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/WudiSWController.php
  routes:
    - 'POST external/api/sw/wudi/get_user_balance'
    - 'POST external/api/sw/wudi/transfer'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\WudiSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/wudi/get_user_balance` | `getUserBalance` |  |  |
| POST | `external/api/sw/wudi/transfer` | `transfer` |  |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 WudiSW 的遊戲內查餘額、下注、拿派彩、被取消投注或領獎勵時，由遊戲商呼叫這個入口；查餘額與轉帳各走一個入口，轉帳再依轉帳類型分投注、派彩、取消投注、獎勵四種。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給遊戲商；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-wudisw](../../rules/vendor-wudisw.md)
