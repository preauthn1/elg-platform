---
type: Table
id: data-model/currencies
title: 幣別主檔
description: 'currencies 是平台認得的標準幣別主檔，六個站點共用同一套編號，充值、取款與換算金額時讀取。'
domain: 金流
tags:
  - currencies
  - 標準幣別
  - 匯率
  - 代收代付匯率
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/Currency.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_currencies_table.php
entity:
  table: currencies
  model: App\Models\Currency
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 系統編號 }
    - { name: code, type: varchar(10), nullable: false, comment: 貨幣代碼 }
    - { name: name, type: varchar(32), nullable: false, comment: 貨幣名稱 }
    - { name: rate, type: 'decimal(18,4)', nullable: false, comment: 匯率 }
    - { name: deposit_rate, type: 'decimal(20,8)', nullable: false, comment: '代收匯率（主要貨幣固定為 1）' }
    - { name: withdrawal_rate, type: 'decimal(20,8)', nullable: false, comment: '代付匯率（主要貨幣固定為 1）' }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:停用,1:啟用' }
    - { name: sort, type: 'int unsigned', nullable: false, comment: 排序 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
  read_by:
    - App\Models\Currency
  written_by:
    - App\Models\Currency
---

<!-- knowledge:generated start -->
## 表

`currencies`

Model：`App\Models\Currency`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 系統編號 |
| `code` | varchar(10) | 否 | 貨幣代碼 |
| `name` | varchar(32) | 否 | 貨幣名稱 |
| `rate` | decimal(18,4) | 否 | 匯率 |
| `deposit_rate` | decimal(20,8) | 否 | 代收匯率（主要貨幣固定為 1） |
| `withdrawal_rate` | decimal(20,8) | 否 | 代付匯率（主要貨幣固定為 1） |
| `status` | tinyint | 否 | 狀態 0:停用,1:啟用 |
| `sort` | int unsigned | 否 | 排序 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
<!-- knowledge:generated end -->

## 做什麼

`currencies` 一列代表一個標準幣別，是平台認得的幣別本身，法幣與虛擬貨幣都算；六個站點共用同一套編號與代碼，一個代碼只會對到一筆。`rate` 是市場匯率，`deposit_rate`、`withdrawal_rate` 分別是代收與代付用的匯率，主要貨幣固定為 1。通道幣別與商戶幣別換算成錢包幣別時，以及會員錢包決定錢包幣別時，都要查這張表確認是不是平台認得的幣別編號。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../rules/currency-and-wallet-currency.md)
