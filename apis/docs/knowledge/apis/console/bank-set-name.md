---
type: Command
id: apis/console/bank-set-name
title: 改取款銀行的顯示名稱
description: 'RD 要統一或修正會員 App 看到的取款銀行名稱時用它批次改名，寫入前先出預檢報表避免同名衝突。'
domain: 金流
tags:
  - bank-set-name
  - 取款銀行
  - 改名
  - 顯示名稱
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SetBankNameCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Bank/SetBankNameCommand.php
  signature: 'bank:set-name'
---

<!-- knowledge:generated start -->
## 入口

`php artisan bank:set-name`

Command：`App\Console\Commands\CashFlow\Bank\SetBankNameCommand`

把指定 banks 列的 name 改成另一個名稱（任一配對不合格則整批零寫入）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--pair` | 選項（可多值） | 改名配對，格式 <banks.id>:<新名稱>（可指定多個；名稱含空白時整個參數加引號） |  |
| `--reason` | 選項（帶值） | 改名理由，寫入審計供日後追溯（必填） |  |
| `--execute` | 選項（旗標） | 實際寫入（預設只輸出預檢報表） |  |
| `--force` | 選項（旗標） | 跳過執行前的環境確認 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 要統一或修正取款銀行在會員 App 上顯示的名稱時執行這支命令，一次可以指定多組「銀行編號換新名稱」的配對，理由必填。預設只輸出預檢報表，列出每組配對改名前後的差異與是否衝突；加上執行參數才會實際寫入，且只改名稱本身，不動代碼、手續費或限額等其他設定。改名要連支付組別層與模板層一起改，否則之後建代付金流商戶時名稱會被模板層蓋回去。規則見相關規則。

## 相關規則

* [rules/standard-bank-list](../../rules/standard-bank-list.md)
