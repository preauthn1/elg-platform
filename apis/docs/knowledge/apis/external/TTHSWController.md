---
type: Api
id: apis/external/TTHSWController
title: EliteHub（TTHSW）無縫錢包回調
description: '聚合商 EliteHub（程式識別名 TTHSW）的無縫錢包回調入口，處理登入驗證、查餘額、扣款、派彩、取消投注、回合結束、活動派彩與錢包加扣款。'
domain: 遊戲與遊戲商
tags:
  - TTHSWController
  - EliteHub
  - TTHSW
  - 聚合商
  - 無縫錢包
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/TTHSWController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/SingleWallets/TTHSWController.php
  routes:
    - 'POST external/api/sw/tthsw/auth'
    - 'POST external/api/sw/tthsw/balance'
    - 'POST external/api/sw/tthsw/deduct'
    - 'POST external/api/sw/tthsw/win'
    - 'POST external/api/sw/tthsw/refund'
    - 'POST external/api/sw/tthsw/complete'
    - 'POST external/api/sw/tthsw/refreshtoken'
    - 'POST external/api/sw/tthsw/promowin'
    - 'POST external/api/sw/tthsw/wallet'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\SingleWallets\TTHSWController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/sw/tthsw/auth` | `auth` |  | `external.tthsw.auth` |
| POST | `external/api/sw/tthsw/balance` | `balance` |  | `external.tthsw.balance` |
| POST | `external/api/sw/tthsw/deduct` | `deduct` |  | `external.tthsw.deduct` |
| POST | `external/api/sw/tthsw/win` | `win` |  | `external.tthsw.win` |
| POST | `external/api/sw/tthsw/refund` | `refund` |  | `external.tthsw.refund` |
| POST | `external/api/sw/tthsw/complete` | `complete` |  | `external.tthsw.complete` |
| POST | `external/api/sw/tthsw/refreshtoken` | `refreshToken` |  | `external.tthsw.refreshtoken` |
| POST | `external/api/sw/tthsw/promowin` | `promoWin` |  | `external.tthsw.promowin` |
| POST | `external/api/sw/tthsw/wallet` | `wallet` |  | `external.tthsw.wallet` |
<!-- knowledge:generated end -->

## 做什麼

玩家在聚合商 EliteHub（把多家遊戲商包成同一套 API 轉給我方的中間商，程式識別名 TTHSW，PEGASUS、PRGM、IBC 等集成的遊戲商都經由它發回調）旗下的遊戲內登入、查餘額、下注、拿派彩、被取消投注、回合結束或拿活動獎金時，由 EliteHub 呼叫這個入口。平台依回調內容增減會員錢包餘額、寫入注單與 TBR，並把最新餘額回給 EliteHub；玩家餘額由我方保管。規則見相關規則。

## 相關規則

* [rules/vendor-tthsw](../../rules/vendor-tthsw.md)
