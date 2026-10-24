---
type: Command
id: apis/console/currency-backfill-vendor-info
title: 回填遊戲商戶與活動商戶的幣別編號
description: 'RD 或維運在幣別主檔治理作業時執行，依幣別 alias 契約回填遊戲商戶與活動商戶還沒有幣別編號的資料。'
domain: 金流
tags:
  - currency-backfill-vendor-info
  - 幣別回填
  - 商戶幣別
  - 幣別主檔
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Currency/BackfillVendorInfoCurrencyCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Currency/BackfillVendorInfoCurrencyCommand.php
  signature: 'currency:backfill-vendor-info'
---

<!-- knowledge:generated start -->
## 入口

`php artisan currency:backfill-vendor-info`

Command：`App\Console\Commands\Currency\BackfillVendorInfoCurrencyCommand`

依幣別 alias 契約把 vendor_infos／event_vendor_infos 的 currency 對到 currency_id 並回填（預設 dry-run，實跑加 --execute）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--execute` | 選項（旗標） | 實際寫入；未指定時只做預覽（dry-run） |  |
| `--table` | 選項（帶值） | 只處理指定資料表（vendor_infos 或 event_vendor_infos），未指定時兩張都處理 |  |
| `--vendor-id` | 選項（帶值） | 只處理指定遊戲商編號（vendors.id） |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或維運在幣別主檔治理作業時執行這支命令，依幣別 alias 契約把遊戲商戶（`vendor_infos`）與活動商戶（`event_vendor_infos`）裡還沒有幣別編號的列，對到幣別主檔的編號並回填。命令預設只預覽，加 `--execute` 才實際寫入，已經有編號的列不會被覆寫；可以用 `--table` 限定只處理其中一張表，或用 `--vendor-id` 限定單一遊戲商。字串對不到幣別主檔的列一律跳過不寫，不會猜測最接近的幣別。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
