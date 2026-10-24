---
type: Api
id: apis/agent/DepositController
title: 代理自助開分
description: 代理後台給代理使用，查詢支付通道與三方往返紀錄，並送出開分申請向金流商付款。
domain: 金流
tags:
  - DepositController
  - 開分
  - 代收
  - 支付通道
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/DepositController.php
    at: '2026-10-21'
entity:
  class: /app/Http/Controllers/Agent/DepositController.php
  routes:
    - 'POST agent/api/deposit/channels'
    - 'POST agent/api/deposit/request-logs'
    - 'POST agent/api/agent/deposit'
---

<!-- knowledge:generated start -->
## 入口

Controller：`App\Http\Controllers\Agent\DepositController`

| 方法 | 路徑 | 動作 | 說明 | 路由名稱 |
|---|---|---|---|---|
| POST | `agent/api/deposit/channels` | `channels` | 取得支付渠道列表 |  |
| POST | `agent/api/deposit/request-logs` | `requestLogs` | 儲值訂單的三方往返紀錄（後台訂單詳情用；內容已依 provider 白名單過濾，見 OrderRequestLogDisplayService） |  |
| POST | `agent/api/agent/deposit` | `deposit` | 代理充值，針對代理 order 請求三方代收 |  |
<!-- knowledge:generated end -->

## 做什麼

代理在代理後台選擇一條支付通道、填金額或金流商要求的欄位後送出，由系統建立開分訂單並向金流商送單。金流商回調確認付款成功後，點數才加進代理自己的錢包，送單當下代理錢包不變。這個入口也供代理後台查詢可用的支付通道清單，以及某張開分訂單的三方往返紀錄。規則見相關規則。

## 相關規則

* [rules/agent-self-deposit](../../rules/agent-self-deposit.md)
