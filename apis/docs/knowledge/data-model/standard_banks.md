---
type: Table
id: data-model/standard_banks
title: 標準銀行
description: 'standard_banks 是平台認得的標準銀行清單，由各幣別設定檔同步產生，供取款銀行與金流商的銀行名稱比對基準。'
domain: 金流
tags:
  - standard_banks
  - 標準銀行
  - 銀行別名
  - 銀行比對
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/StandardBank.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2026_05_14_000001_create_standard_banks_and_bank_mapping.php
entity:
  table: standard_banks
  model: App\Models\StandardBank
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: code, type: varchar(64), nullable: false, comment: 標準銀行代碼（全大寫，不含幣別前綴） }
    - { name: currency_id, type: 'int unsigned', nullable: false, comment: '幣別 ID，對應 currencies.id' }
    - { name: currency_code, type: varchar(10), nullable: true, comment: '幣別代碼（冗餘欄位），如 MYR / VND / IDR' }
    - { name: type, type: tinyint, nullable: false, comment: '類型; 1:銀行帳號, 2:手機/電子錢包, 3:加密貨幣' }
    - { name: name, type: varchar(100), nullable: false, comment: 銀行英文名稱（預設顯示） }
    - { name: swift, type: varchar(11), nullable: true, comment: 'SWIFT BIC，可為 null' }
    - { name: aliases, type: json, nullable: true, comment: '銀行關鍵字/縮寫陣列（JSON），供 mapping 正規化比對' }
    - { name: remark, type: varchar(255), nullable: true, comment: 備註 }
    - { name: status, type: tinyint, nullable: false, comment: '狀態: 1=啟用, 0=停用' }
    - { name: created_at, type: timestamp, nullable: true }
    - { name: updated_at, type: timestamp, nullable: true }
  read_by:
    - App\Models\StandardBank
  written_by:
    - App\Models\StandardBank
---

<!-- knowledge:generated start -->
## 表

`standard_banks`

Model：`App\Models\StandardBank`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `code` | varchar(64) | 否 | 標準銀行代碼（全大寫，不含幣別前綴） |
| `currency_id` | int unsigned | 否 | 幣別 ID，對應 currencies.id |
| `currency_code` | varchar(10) | 是 | 幣別代碼（冗餘欄位），如 MYR / VND / IDR |
| `type` | tinyint | 否 | 類型; 1:銀行帳號, 2:手機/電子錢包, 3:加密貨幣 |
| `name` | varchar(100) | 否 | 銀行英文名稱（預設顯示） |
| `swift` | varchar(11) | 是 | SWIFT BIC，可為 null |
| `aliases` | json | 是 | 銀行關鍵字/縮寫陣列（JSON），供 mapping 正規化比對 |
| `remark` | varchar(255) | 是 | 備註 |
| `status` | tinyint | 否 | 狀態: 1=啟用, 0=停用 |
| `created_at` | timestamp | 是 |  |
| `updated_at` | timestamp | 是 |  |
<!-- knowledge:generated end -->

## 做什麼

`standard_banks` 一列代表一家標準銀行，是平台認得的銀行本身，各站點的取款銀行與金流商回報的銀行名稱都拿它當比對基準。`code` 帶幣別前綴（格式「幣別_名稱」），`aliases` 存銀行關鍵字與縮寫陣列，供名稱比對使用。清單的唯一來源是程式裡的各幣別設定檔，每次部署由同步流程覆蓋這張表；已合併或改名的銀行會被設成停用（`status` 為 0），名稱併進現在那一家的別名。規則見相關規則。

## 相關規則

* [rules/standard-bank-list](../rules/standard-bank-list.md)
