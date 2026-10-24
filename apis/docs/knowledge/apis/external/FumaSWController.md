---
type: Api
id: apis/external/FumaSWController
title: 'FumaSW 無縫錢包回調'
description: '遊戲商 FumaSW（含一般與免盤費兩種遊戲商戶）的回調入口，處理查餘額、下注與派彩。'
domain: 遊戲與遊戲商
tags:
  - FumaSWController
  - FumaSW
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/FumaSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/FumaSWController.php
  routes:
    - 'POST external/api/sw/fuma'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\FumaSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/fuma` | `apiSwitch` | FumaSW 171 |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 FumaSW（含一般與免盤費兩種遊戲商戶，兩者共用這個入口、由遊戲商戶代碼分辨）的遊戲內查餘額、下注、取得派彩時，由 FumaSW 呼叫這個入口，依請求內的動作代碼分派到對應處理。下注與派彩各自寫入一筆注單。平台依回調內容增減會員錢包餘額，並把最新餘額回給 FumaSW；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-fumasw](../../rules/vendor-fumasw.md)
