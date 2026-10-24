---
type: Command
id: apis/console/bank-reactivate-cfb-rows
title: 重新啟用停用的代付銀行碼對照列
description: 'RD 在代付銀行碼同步命令停用了人工修正過的對照列之後，用它把指定列改回啟用，並視需要一併校正代付銀行碼。'
domain: 金流
tags:
  - bank-reactivate-cfb-rows
  - 代付銀行碼
  - 對照列
  - 重新啟用
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/ReactivateCfbRowsCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Bank/ReactivateCfbRowsCommand.php
  signature: 'bank:reactivate-cfb-rows'
---

<!-- knowledge:generated start -->
## 入口

`php artisan bank:reactivate-cfb-rows`

Command：`App\Console\Commands\CashFlow\Bank\ReactivateCfbRowsCommand`

把停用的 cash_flow_banks 列改回啟用，可選配改三方碼（預設只預演）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--cfb` | 選項（可多值） | 要重新啟用的 cash_flow_banks.id（可指定多個） |  |
| `--from-third` | 選項（帶值） | 該列現行的三方碼（要改三方碼時必填，防改錯列） |  |
| `--to-third` | 選項（帶值） | 重新啟用後的三方碼（不給就維持現值） |  |
| `--allow-alias` | 選項（旗標） | 明示放行「與同渠道其他啟用列共用同一個三方碼」 |  |
| `--reason` | 選項（帶值） | 重新啟用的理由與依據，寫入審計與人工覆寫標記（必填） |  |
| `--execute` | 選項（旗標） | 實際寫入（預設只輸出預檢報表） |  |
| `--force` | 選項（旗標） | 跳過執行前的環境確認 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 在跑過代付銀行碼同步命令、發現原本人工修好的對照列被一併停用之後，執行這支命令把指定的對照列改回啟用狀態，必須填寫理由。可以在重新啟用的同時一併把代付銀行碼改成新值；寫入前會先做多道預檢，例如銀行是否存在、同一我方銀行代碼是否已有另一列啟用中、新代號是否與金流商戶的代付銀行清單一致，任一不過就整批不寫入。寫入成功後會替該列加上人工覆寫標記，之後同步命令不再覆寫它的代付銀行碼。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../../rules/payout-bank-code-mapping.md)
