---
type: Api
id: apis/external/Sky1388Controller
title: 'Sky1388 確認轉帳回調'
description: '轉帳錢包遊戲商 Sky1388 反向確認轉帳的回調入口，核對一筆轉入或轉出是否確實由我方發起。'
domain: 遊戲與遊戲商
tags:
  - Sky1388Controller
  - Sky1388
  - 轉帳錢包
  - 回調
  - 確認轉帳
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/TransferWallets/Sky1388Controller.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/External/TransferWallets/Sky1388Controller.php
  routes:
    - 'POST external/api/tw/sky1388/confirm_transfer'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\External\TransferWallets\Sky1388Controller`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `external/api/tw/sky1388/confirm_transfer` | `confirmTransfer` | Sky1388 APIs, 確認轉帳（防偽造上下分） |  |
<!-- knowledge:generated end -->

## 做什麼

我方把會員餘額轉入或轉出轉帳錢包遊戲商 Sky1388（玩家進遊戲前先把餘額轉進遊戲商的錢包，遊戲過程中由遊戲商控管額度，離開遊戲時再轉回我方）時，Sky1388 會反向呼叫這個入口，確認這筆轉帳確實由我方發起，防止有人偽造轉帳。這個入口只核對轉帳單號是否存在，不異動會員錢包，也不寫入任何資料。規則見相關規則。

## 相關規則

* [rules/vendor-sky1388](../../rules/vendor-sky1388.md)
