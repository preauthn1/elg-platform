---
type: Api
id: apis/external/AFBSwController
title: 'AFBSW 無縫錢包回調'
description: '遊戲商 AFB Gaming 的無縫錢包版（AFBSW）回調入口，處理查餘額、下注、派彩與取消投注。'
domain: 遊戲與遊戲商
tags:
  - AFBSwController
  - AFBSW
  - 'AFB Gaming'
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AFBSwController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/AFBSwController.php
  routes:
    - 'POST external/api/sw/afb'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\AFBSwController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/afb` | `__invoke` | AFB |  |
<!-- knowledge:generated end -->

## 做什麼

玩家在遊戲商 AFB Gaming 的無縫錢包版（AFBSW）遊戲內查餘額、下注、取得派彩，或 AFB 取消投注時，由 AFB 呼叫這個入口，依請求裡的方法名稱分辨要做什麼。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 AFB；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-afbsw](../../rules/vendor-afbsw.md)
