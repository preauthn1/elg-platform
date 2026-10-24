---
type: Table
id: data-model/cash_flow_banks
title: 代付銀行碼對照
description: 'cash_flow_banks 記錄金流商、幣別與我方取款銀行之間的代付銀行碼對照，由 cash-flow:sync 等命令維護，代付送件時讀取。'
domain: 金流
tags:
  - cash_flow_banks
  - 代付銀行碼
  - 取款銀行
  - 標準銀行
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/CashFlowBank.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2025_11_25_024510_create_cash_flow_banks_table.php
entity:
  table: cash_flow_banks
  model: App\Models\CashFlowBank
  columns:
    - { name: id, type: 'bigint unsigned', nullable: false }
    - { name: cash_flow_id, type: 'bigint unsigned', nullable: false, comment: '對應 cash_flows.id' }
    - { name: currency_id, type: 'bigint unsigned', nullable: false, comment: '幣別 ID' }
    - { name: bank_id, type: 'bigint unsigned', nullable: false, comment: '對應 banks.id（平台銀行主檔）' }
    - { name: standard_bank_id, type: 'bigint unsigned', nullable: true, comment: '標準銀行 ID 便利欄，由 bank:sync-projection 投影回填；對應 standard_banks.id' }
    - { name: bank_code, type: varchar(64), nullable: false, comment: '我方銀行代碼，對應 banks.code' }
    - { name: provider_bank_code, type: varchar(64), nullable: false, comment: 第三方金流提供的銀行代碼 }
    - { name: manual_override_at, type: timestamp, nullable: true, comment: '人工覆寫 provider_bank_code 的時間；非 null 時 cash-flow:sync 不覆寫該欄' }
    - { name: manual_override_note, type: varchar(255), nullable: true, comment: '人工覆寫的原因與出處，例：target/op_tickets#3174 FPay 按商戶帳號換碼' }
    - { name: min_amount, type: 'decimal(20,4)', nullable: false, comment: 渠道層級最低金額限制 }
    - { name: max_amount, type: 'decimal(20,4)', nullable: false, comment: '渠道層級最高金額限制（0 = 不限制）' }
    - { name: status, type: tinyint, nullable: false, comment: '狀態: 1=啟用, 0=停用' }
    - { name: info, type: text, nullable: true, comment: 額外設定，如支行資訊等 }
    - { name: created_at, type: timestamp, nullable: true }
    - { name: updated_at, type: timestamp, nullable: true }
  read_by:
    - App\Models\CashFlowBank
  written_by:
    - App\Models\CashFlowBank
---

<!-- knowledge:generated start -->
## 表

`cash_flow_banks`

Model：`App\Models\CashFlowBank`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | bigint unsigned | 否 |  |
| `cash_flow_id` | bigint unsigned | 否 | 對應 cash_flows.id |
| `currency_id` | bigint unsigned | 否 | 幣別 ID |
| `bank_id` | bigint unsigned | 否 | 對應 banks.id（平台銀行主檔） |
| `standard_bank_id` | bigint unsigned | 是 | 標準銀行 ID 便利欄，由 bank:sync-projection 投影回填；對應 standard_banks.id |
| `bank_code` | varchar(64) | 否 | 我方銀行代碼，對應 banks.code |
| `provider_bank_code` | varchar(64) | 否 | 第三方金流提供的銀行代碼 |
| `manual_override_at` | timestamp | 是 | 人工覆寫 provider_bank_code 的時間；非 null 時 cash-flow:sync 不覆寫該欄 |
| `manual_override_note` | varchar(255) | 是 | 人工覆寫的原因與出處，例：target/op_tickets#3174 FPay 按商戶帳號換碼 |
| `min_amount` | decimal(20,4) | 否 | 渠道層級最低金額限制 |
| `max_amount` | decimal(20,4) | 否 | 渠道層級最高金額限制（0 = 不限制） |
| `status` | tinyint | 否 | 狀態: 1=啟用, 0=停用 |
| `info` | text | 是 | 額外設定，如支行資訊等 |
| `created_at` | timestamp | 是 |  |
| `updated_at` | timestamp | 是 |  |
<!-- knowledge:generated end -->

## 做什麼

`cash_flow_banks` 一列代表某金流商、某幣別底下，我方一家取款銀行對應的代付銀行碼：代付送件時用它把我方銀行代碼換成金流商要的代付銀行碼。`provider_bank_code` 是送給金流商的代付銀行碼，`standard_bank_id` 是回填的標準銀行便利欄位，供名稱比對使用。`manual_override_at` 非空時代表這一列已經人工修正過，同步命令不會覆寫它的代付銀行碼。這張表由 `cash-flow:sync` 等命令維護，代付流程建單與送件時讀取。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../rules/payout-bank-code-mapping.md)
