---
type: Command
id: apis/console/cash-flow-seed-standard-banks
title: 手動同步標準銀行清單
description: 'RD 在部署前，用它把各幣別標準銀行設定檔的內容手動同步到站台的標準銀行表。'
domain: 金流
tags:
  - cash-flow-seed-standard-banks
  - 標準銀行
  - 同步
  - 部署
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SeedStandardBanksCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Bank/SeedStandardBanksCommand.php
  signature: 'cash-flow:seed-standard-banks'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:seed-standard-banks`

Command：`App\Console\Commands\CashFlow\Bank\SeedStandardBanksCommand`

從各幣別標準銀行分檔（app/Console/Commands/CashFlow/Data/banks）冪等 upsert standard_banks（rich schema）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--dry-run` | 選項（旗標） | 僅預覽不寫入 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

這支命令把程式裡各幣別的標準銀行設定檔內容，寫入站台的標準銀行表，正常情況下由部署流程自動執行一次。RD 需要在部署前先看到結果，或想單獨重跑時，可以手動執行同一支命令，它會先顯示設定檔有多少筆可寫入、多少筆會設成停用、略過幾筆，再實際寫入；加上預覽參數時只顯示結果、不寫入。更新完標準銀行表後，各站取款銀行對到哪一家標準銀行不會自動跟著變，需要另外執行回填比對的命令。規則見相關規則。

## 相關規則

* [rules/standard-bank-list](../../rules/standard-bank-list.md)
