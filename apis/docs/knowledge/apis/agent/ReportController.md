---
type: Api
id: apis/agent/ReportController
title: 'SR12 下注記錄報表'
description: '代理後台查詢 SR12（下注記錄）報表的入口，回傳押注、有效投注、派彩、輸贏總數與分頁注單明細。'
domain: 報表
tags:
  - ReportController
  - SR12
  - 下注記錄
  - 報表
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/ReportController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/Agent/ReportController.php
  routes:
    - 'POST agent/api/report/sr12'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Agent\ReportController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `agent/api/report/sr12` | `sr12` |  |  |
<!-- knowledge:generated end -->

## 做什麼

代理在代理後台查詢 SR12（下注記錄報表）時呼叫這個入口：依遊戲商、時間區間（投注時間或結算時間）、下層代理或會員篩選 TBR（各家注單轉成同一組欄位之後的注單），沒指定下層代理或會員時預設只查該代理自己與其下層的範圍。回應含總數區的押注、有效投注、派彩、輸贏彙總，以及分頁的注單明細。規則見相關規則。

## 相關規則

* [rules/report-sr12-payout](../../rules/report-sr12-payout.md)
