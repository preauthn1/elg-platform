---
type: Command
id: apis/console/cash-flow-sync
title: 匯入金流商的支付通道與代付銀行碼設定
description: 'RD 在接入或調整金流商設定時執行，依共用設定檔同步金流商戶底下的支付通道、取款銀行與代付銀行碼對照。'
domain: 金流
tags:
  - cash-flow-sync
  - 代付銀行碼
  - 支付通道
  - 標準銀行
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php
  signature: 'cash-flow:sync'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:sync`

Command：`App\Console\Commands\CashFlow\SyncCashFlowsCommand`

同步 CashFlow 及關聯的 OrderType、Bank 資料（支援 --dry-run 預覽模式、--currency 幣別過濾）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--ids` | 選項（帶值） | 指定要處理的 CashFlow ID，多個 ID 用逗號分隔，例如：1,2,3 |  |
| `--currency` | 選項（帶值） | 指定幣別 ID 或代碼，只建立該幣別的渠道和銀行。例如：VND、SGD、3、2（支援：MYR=1, SGD=2, VND=3, IDR=6, THB=9, USDT=10, HKD=16） |  |
| `--rename-key` | 選項（可多值） | 顯式改鍵 舊key=新key（config key 修正時就地 update order_types.key；OT id 不變、ACF/ACFOT 關聯自動跟上；可多次指定） |  |
| `--dry-run` | 選項（旗標） | 乾跑模式，預覽操作但不實際寫入資料庫 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 在新接入一家金流商、或金流商異動了通道與銀行代碼設定時，在命令列執行這支命令。命令依共用設定檔建立或更新金流商戶底下的支付通道、取款銀行，並把金流商的代付銀行名稱比對到我方的取款銀行，掛上對應的代付銀行碼。可以用 `--currency` 限定只處理某個幣別，或用 `--dry-run` 只預覽、不寫入資料庫。比對不到或信心度不足的銀行名稱會被跳過、不覆寫，已經加上人工覆寫標記的對照列也不會被這支命令改掉代付銀行碼。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../../rules/payout-bank-code-mapping.md)
* [rules/standard-bank-list](../../rules/standard-bank-list.md)
