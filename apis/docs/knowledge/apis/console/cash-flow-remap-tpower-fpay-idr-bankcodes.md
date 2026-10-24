---
type: Command
id: apis/console/cash-flow-remap-tpower-fpay-idr-bankcodes
title: '把 TPower FPay 印尼盾代付銀行碼換成現行清單'
description: '一次性工具，RD 用它把 TPower 站 FPay 印尼盾代付的銀行代號改成 FPay 現行清單上的新代號，或反向以代付設定校正對照表。'
domain: 金流
tags:
  - cash-flow-remap-tpower-fpay-idr-bankcodes
  - TPower
  - FPay
  - 換碼
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Guard/RemapTpowerFpayIdrBankCodes.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Guard/RemapTpowerFpayIdrBankCodes.php
  signature: 'cash-flow:remap-tpower-fpay-idr-bankcodes'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:remap-tpower-fpay-idr-bankcodes`

Command：`App\Console\Commands\CashFlow\Guard\RemapTpowerFpayIdrBankCodes`

把 TPower FPay 印尼盾代付的銀行代號換成 FPay 現行清單的代號（預設只出報表）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--execute` | 選項（旗標） | 實際寫入（預設僅出報表） |  |
| `--force` | 選項（旗標） | 跳過執行前確認 |  |
| `--detail` | 選項（旗標） | 連同 118 筆對照逐列印出 |  |
| `--align-from-payout-config` | 選項（旗標） | 改跑校正：以代付設定的 available_banks 為準，把 cash_flow_banks 對不上的三方代號改回來 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

這是一支只適用 TPower 站 FPay 印尼盾兩個金流商戶的一次性工具，RD 在 FPay 這批銀行代號全面換過之後用它把對照表更新成新代號。換碼前會先向 FPay 查現行清單核對，兩份清單對不上、新代號不在清單上、或代號新舊混雜判斷不出來時，整批攔截、不寫入。另外提供校正模式，改以代付設定的清單為準，把對照表裡對不上的代號改回來；預設只輸出報表，加上執行參數才會實際寫入，寫入時會替改到的列加上人工覆寫標記。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../../rules/payout-bank-code-mapping.md)
