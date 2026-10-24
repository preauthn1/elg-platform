---
type: Api
id: apis/agent/Sr02nController
title: 'SR02N 開洗分明細表'
description: 代理後台給代理與客服使用，查詢一段期間內的開分、洗分與充值明細、合計，以及來源篩選用的金流商三層樹。
domain: 報表
tags:
  - Sr02nController
  - 開分
  - 洗分
  - 統計報表
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/Report/Sr02nController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Agent/Report/Sr02nController.php
  routes:
    - 'POST agent/api/report/sr02n/list'
    - 'POST agent/api/report/sr02n/total'
    - 'POST agent/api/report/sr02n/cash-flow-tree'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Agent\Report\Sr02nController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `agent/api/report/sr02n/list` | `list` |  |  |
| POST | `agent/api/report/sr02n/total` | `total` |  |  |
| POST | `agent/api/report/sr02n/cash-flow-tree` | `cashFlowTree` |  |  |
<!-- knowledge:generated end -->

## 做什麼

代理或客服在代理後台查詢一段期間內的開分、洗分、充值與相關帳變紀錄，可依操作者與對象配對、金流商、支付通道等條件篩選，並查看總開分、總洗分、手續費與實際金額的合計。金流商三層樹供「來源」篩選的下拉選單使用，依登入者可見的代理線與支付組別過濾可選的金流商、金流商戶與支付通道。規則見相關規則。

## 相關規則

* [rules/report-sr02n](../../rules/report-sr02n.md)
