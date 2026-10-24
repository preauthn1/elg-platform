---
type: Command
id: apis/console/bank-sync-mappings
title: 把取款銀行對到標準銀行
description: 'RD 在標準銀行清單更新後，用它把各站取款銀行依名稱比對回標準銀行，或重驗既有的對應關係。'
domain: 金流
tags:
  - bank-sync-mappings
  - 標準銀行
  - 比對
  - 回填
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SyncBankMappingsCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Bank/SyncBankMappingsCommand.php
  signature: 'bank:sync-mappings'
---

<!-- knowledge:generated start -->
## 入口

`php artisan bank:sync-mappings`

Command：`App\Console\Commands\CashFlow\Bank\SyncBankMappingsCommand`

回填 banks.standard_bank_id：tier1/2 直配與兩跳、tier3 alias 精確（自動）；<95 產人工複核佇列；--repair 重驗既有值

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--dry-run` | 選項（旗標） | 僅預覽不寫入 |  |
| `--export-review` | 選項（帶值） | 匯出人工複核候選 CSV（信心度 <95）至指定檔名（storage/app 下） |  |
| `--apply-reviewed` | 選項（帶值） | 套用已裁決的複核 CSV（approve 欄 =1/y 者寫入），與同步互斥 |  |
| `--repair` | 選項（旗標） | 重驗既有 standard_bank_id，與 standard_banks 不符者按 name/alias 精確比對改正，與同步互斥 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 在標準銀行清單同步更新之後執行這支命令，把尚未對到標準銀行的取款銀行，依名稱或別名完全相同的規則自動掛上對應的標準銀行。加上重驗參數時改成只處理已經對上的取款銀行，重新比對一次，名稱確定對到別家時改過去，對不到任何一家時保留原本的對應不動。兩種模式都可以先加預覽參數查看結果，不寫入資料庫。規則見相關規則。

## 相關規則

* [rules/standard-bank-list](../../rules/standard-bank-list.md)
