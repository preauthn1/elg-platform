---
type: Command
id: apis/console/create_vendor_account
title: 預產遊戲帳號供用戶綁定
description: 系統怎麼預先向遊戲商建好玩家帳號放進帳號池，以及怎麼手動補建。
domain: 遊戲與遊戲商
tags:
  - create_vendor_account
  - 帳號池
  - 預建帳號
  - 補建遊戲帳號
  - 排程
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/CreateVendorAccount.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/Schedule/CreateVendorAccount.php
  signature: create_vendor_account
  schedule:
    - '*/5 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan create_vendor_account`

Command：`App\Console\Commands\Schedule\CreateVendorAccount`

預產遊戲帳號供用戶綁定

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--vendor-id` | 選項（帶值） | 指定要處理的遊戲商 ID |  |
| `--direct` | 選項（旗標） | 同步執行不派發 queue（後台救援用） |  |
| `--dry-run` | 選項（旗標） | 乾跑模式，不寫入資料庫 |  |

排程：`*/5 * * * *`
<!-- knowledge:generated end -->

## 做什麼

排程每 5 分鐘自動執行，依各遊戲商戶的預備帳號數量與目前的可綁定帳號數算出缺口，向遊戲商預先建立玩家帳號放進帳號池，供會員第一次進遊戲時直接綁定、不必等遊戲商即時建號；Olympus「系統操作 › 補建遊戲帳號」頁也能指定單一遊戲商立即手動執行同一套邏輯。規則見相關規則。

## 相關規則

* [rules/vendor-account-pool](../../rules/vendor-account-pool.md)
