---
type: Command
id: apis/console/currency-audit-vendor-info
title: 稽核遊戲商戶幣別能否對到標準幣別
description: 'RD 唯讀稽核命令，列出遊戲商戶的幣別字串對不到標準幣別、會讓幣別編號回填落空的商戶。'
domain: 金流
tags:
  - currency-audit-vendor-info
  - 幣別
  - 商戶幣別
  - 稽核
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Currency/AuditVendorInfoCurrencyCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Currency/AuditVendorInfoCurrencyCommand.php
  signature: 'currency:audit-vendor-info'
---

<!-- knowledge:generated start -->
## 入口

`php artisan currency:audit-vendor-info`

Command：`App\Console\Commands\Currency\AuditVendorInfoCurrencyCommand`

稽核 vendor_infos.currency 能否對到標準幣別，列出會讓 currency_id 回填落空的代碼

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--json` | 選項（旗標） | 以 JSON 輸出，供程式解析 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 在執行幣別編號回填命令前後，用這支唯讀命令稽核遊戲商戶的幣別字串能否解析到幣別主檔的編號。有啟用中的商戶對不到標準幣別時，命令以失敗結束；對不到的只有停用中的商戶時，只列出、不算失敗。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
