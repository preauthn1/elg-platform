---
type: Table
id: data-model/vendor_infos
title: 遊戲商戶
description: 'vendor_infos 是遊戲商戶主檔，記錄每個遊戲商戶的連接憑證、幣別與帳號池設定。'
domain: 遊戲與遊戲商
tags:
  - vendor_infos
  - 遊戲商戶
  - 商戶幣別
  - 帳號池
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/VendorInfo.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_vendor_infos_table.php
entity:
  table: vendor_infos
  model: App\Models\VendorInfo
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: name, type: varchar(32), nullable: false, comment: 方便識別的名稱 }
    - { name: type, type: tinyint, nullable: false, comment: '遊戲商環境，0:測試，1:正式' }
    - { name: vendor_id, type: int, nullable: false, comment: 遊戲商系統編號 }
    - { name: currency, type: varchar(5), nullable: false, comment: 幣種代碼 }
    - { name: currency_id, type: 'int unsigned', nullable: false, comment: '標準幣別編號（currencies.id），0=尚未回填；與 currency 字串並存，字串進三方 payload、本欄供查詢與統計' }
    - { name: rate, type: 'decimal(11,5)', nullable: false, comment: 對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形 }
    - { name: transfer_min_amount, type: 'decimal(11,5)', nullable: true }
    - { name: info, type: text, nullable: false, comment: 連接資訊 }
    - { name: prepared_account_number, type: int, nullable: false, comment: 預備帳號數量 }
    - { name: account_prefix, type: varchar(10), nullable: false, comment: 帳號前綴 }
    - { name: cost, type: 'decimal(10,4)', nullable: false }
    - { name: remark, type: text, nullable: true }
    - { name: status, type: tinyint(1), nullable: false, comment: '狀態，0:停用、1:啟用' }
    - { name: rolling_chip_status, type: tinyint(1), nullable: false, comment: '禮碼開關 1:開啟 0:關閉' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
    - { name: deposit_limit, type: 'decimal(18,4)', nullable: false, comment: '存款限制, 0 = 無限制' }
  read_by:
    - App\Models\VendorInfo
  written_by:
    - App\Models\VendorInfo
---

<!-- knowledge:generated start -->
## 表

`vendor_infos`

Model：`App\Models\VendorInfo`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `name` | varchar(32) | 否 | 方便識別的名稱 |
| `type` | tinyint | 否 | 遊戲商環境，0:測試，1:正式 |
| `vendor_id` | int | 否 | 遊戲商系統編號 |
| `currency` | varchar(5) | 否 | 幣種代碼 |
| `currency_id` | int unsigned | 否 | 標準幣別編號（currencies.id），0=尚未回填；與 currency 字串並存，字串進三方 payload、本欄供查詢與統計 |
| `rate` | decimal(11,5) | 否 | 對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形 |
| `transfer_min_amount` | decimal(11,5) | 是 |  |
| `info` | text | 否 | 連接資訊 |
| `prepared_account_number` | int | 否 | 預備帳號數量 |
| `account_prefix` | varchar(10) | 否 | 帳號前綴 |
| `cost` | decimal(10,4) | 否 |  |
| `remark` | text | 是 |  |
| `status` | tinyint(1) | 否 | 狀態，0:停用、1:啟用 |
| `rolling_chip_status` | tinyint(1) | 否 | 禮碼開關 1:開啟 0:關閉 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
| `deposit_limit` | decimal(18,4) | 否 | 存款限制, 0 = 無限制 |
<!-- knowledge:generated end -->

## 做什麼

這張表是遊戲商戶主檔，一列代表我方在某一家遊戲商那邊開的一組身分：記錄連接資訊（API 憑證）、商戶幣別與匯率、帳號池的預備帳號數量與帳號前綴。帳號池補建、注單彙總與拉取注單等流程都依這張表找出對應的遊戲商戶與其設定。規則見相關規則。

## 相關規則

* [rules/vendor-account-pool](../rules/vendor-account-pool.md)
