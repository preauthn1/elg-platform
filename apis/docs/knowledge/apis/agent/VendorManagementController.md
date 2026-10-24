---
type: Api
id: apis/agent/VendorManagementController
title: 遊戲商管理
description: 代理後台查詢遊戲商清單、調整無縫錢包遊戲商回調路徑的入口。
domain: 遊戲與遊戲商
tags:
  - VendorManagementController
  - 遊戲商
  - 無縫錢包
  - 回調路徑
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/VendorManagementController.php
    at: '2026-10-22'
entity:
  class: /app/Http/Controllers/Agent/VendorManagementController.php
  routes:
    - 'POST agent/api/vendor-management/list'
    - 'POST agent/api/vendor-management/callback-path'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Agent\VendorManagementController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `agent/api/vendor-management/list` | `list` |  |  |
| POST | `agent/api/vendor-management/callback-path` | `updateCallbackPath` |  |  |
<!-- knowledge:generated end -->

## 做什麼

代理後台角色為 admin 的使用者查詢遊戲商清單、或調整某家遊戲商的無縫錢包回調路徑後綴時，呼叫這兩個入口。清單回傳全部狀態的遊戲商，並附帶錢包模式、接入通道、有無 gamelist API、回調路徑與完整回調網址；回調路徑只能套用在有掛 Driver 的無縫錢包遊戲商，轉帳錢包會被拒絕，非 admin 的使用者看不到回調相關欄位。規則見相關規則。

## 相關規則

* [rules/vendor-and-channel-management](../../rules/vendor-and-channel-management.md)
