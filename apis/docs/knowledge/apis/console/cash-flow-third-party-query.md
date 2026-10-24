---
type: Command
id: apis/console/cash-flow-third-party-query
title: 查詢金流商戶的通道限額與代付銀行清單
description: 'RD 或維運人員用命令向金流商查某個金流商戶的限額與支援銀行，或查單筆代收訂單狀態，核對與我方設定是否一致。'
domain: 金流
tags:
  - cash-flow-third-party-query
  - 代付銀行碼
  - 金流商查詢
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/ThirdPartyQuery.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/ThirdPartyQuery.php
  signature: 'cash-flow:third-party-query'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:third-party-query`

Command：`App\Console\Commands\CashFlow\ThirdPartyQuery`

查三方商戶通道配置（限額／支援銀行）並與我方 ACF 比對；--order 查單筆代收訂單。依 provider 能力介面偵測支援範圍

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `acf` | 引數（必填） | 要查詢的 agent_cash_flows.id |  |
| `--order` | 選項（帶值） | 以我方訂單號向三方查該筆代收訂單（需 provider 支援） |  |
| `--raw` | 選項（旗標） | 直接輸出三方回應原始 JSON（已遮罩），不做比對 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或維運人員在排查金流商戶設定、或核對代付銀行碼對照是否正確時，在命令列執行這支命令。命令向金流商查詢指定金流商戶的限額與支援銀行清單，並與我方對照表比對，列出我方有、金流商沒有的代號；也可以改用我方的訂單號，向金流商查一筆代收訂單的狀態。查詢只讀，不會修改任何資料，回應裡的憑證類內容會先遮罩再輸出。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../../rules/payout-bank-code-mapping.md)
