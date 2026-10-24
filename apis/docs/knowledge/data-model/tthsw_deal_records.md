---
type: Table
id: data-model/tthsw_deal_records
title: 'EliteHub 回調交易紀錄'
description: 'tthsw_deal_records 記錄 EliteHub（TTHSW）每筆已處理回調的異動前後餘額，用來判斷回調重送。'
domain: 遊戲與遊戲商
tags:
  - tthsw_deal_records
  - EliteHub
  - TTHSW
  - 回調
  - 重送
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/TTHSWDealRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_12_31_063315_create_tthsw_deal_records_table.php
entity:
  table: tthsw_deal_records
  model: App\Vendors\Models\TTHSWDealRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: game_code, type: varchar(255), nullable: true, comment: 遊戲代碼 }
    - { name: game_round_id, type: varchar(255), nullable: false, comment: 'Round Id' }
    - { name: transaction_id, type: varchar(255), nullable: false, comment: 'Transaction Id' }
    - { name: transaction_date, type: datetime, nullable: false, comment: TransactionDate }
    - { name: amount, type: 'decimal(18,4)', nullable: false, comment: 金額 }
    - { name: start_balance, type: 'decimal(18,4)', nullable: false, comment: 交易前餘額 }
    - { name: end_balance, type: 'decimal(18,4)', nullable: false, comment: 交易後餘額 }
    - { name: type, type: tinyint, nullable: false, comment: Type }
    - { name: action, type: varchar(255), nullable: false, comment: 請求動作 }
    - { name: info, type: text, nullable: true, comment: 'Response data' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\TTHSWDealRecord
  written_by:
    - App\Vendors\Models\TTHSWDealRecord
---

<!-- knowledge:generated start -->
## 表

`tthsw_deal_records`

Model：`App\Vendors\Models\TTHSWDealRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `game_code` | varchar(255) | 是 | 遊戲代碼 |
| `game_round_id` | varchar(255) | 否 | Round Id |
| `transaction_id` | varchar(255) | 否 | Transaction Id |
| `transaction_date` | datetime | 否 | TransactionDate |
| `amount` | decimal(18,4) | 否 | 金額 |
| `start_balance` | decimal(18,4) | 否 | 交易前餘額 |
| `end_balance` | decimal(18,4) | 否 | 交易後餘額 |
| `type` | tinyint | 否 | Type |
| `action` | varchar(255) | 否 | 請求動作 |
| `info` | text | 是 | Response data |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

EliteHub（聚合商，程式識別名 TTHSW）的回調（下注、派彩、取消、回合結束、活動派彩、錢包加扣款）處理完成時，系統在這張表寫一筆交易紀錄，記下這次異動前後的會員錢包餘額。同一局、同一交易序號、同一交易類型的回調重送時，靠這張表判斷已經處理過，回原本的餘額，不重複動錢包。規則見相關規則。

## 相關規則

* [rules/vendor-tthsw](../rules/vendor-tthsw.md)
