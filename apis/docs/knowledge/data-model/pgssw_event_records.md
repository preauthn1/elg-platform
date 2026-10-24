---
type: Table
id: data-model/pgssw_event_records
title: 'target 活動派彩紀錄'
description: 'pgssw_event_records 記錄 target（含 BWG、TIGER9）活動派彩回調發放的獎勵明細，由 target 回調流程寫入。'
domain: 遊戲與遊戲商
tags:
  - pgssw_event_records
  - target
  - PGSSW
  - BWG
  - TIGER9
  - 活動派彩
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/PgsswEventRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_pgssw_event_records_table.php
entity:
  table: pgssw_event_records
  model: App\Vendors\Models\PgsswEventRecord
  columns:
    - { name: id, type: 'bigint unsigned', nullable: false }
    - { name: bet_id, type: varchar(191), nullable: false, comment: '注单唯一Id, ThemeId' }
    - { name: game_code, type: varchar(45), nullable: false, comment: 用來顯示報表遊戲類別 }
    - { name: username, type: varchar(30), nullable: false, comment: 玩家帳號 }
    - { name: pay_time, type: datetime, nullable: false, comment: 派獎時間(utc) }
    - { name: prize_amount, type: 'decimal(18,4)', nullable: false, comment: 獎勵金額 }
    - { name: info, type: text, nullable: false, comment: 'response data' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\PgsswEventRecord
  written_by:
    - App\Vendors\Models\PgsswEventRecord
---

<!-- knowledge:generated start -->
## 表

`pgssw_event_records`

Model：`App\Vendors\Models\PgsswEventRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | bigint unsigned | 否 |  |
| `bet_id` | varchar(191) | 否 | 注单唯一Id, ThemeId |
| `game_code` | varchar(45) | 否 | 用來顯示報表遊戲類別 |
| `username` | varchar(30) | 否 | 玩家帳號 |
| `pay_time` | datetime | 否 | 派獎時間(utc) |
| `prize_amount` | decimal(18,4) | 否 | 獎勵金額 |
| `info` | text | 否 | response data |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

收到 target（程式裡叫 PGSSW，含經它接入的 BWG 與 TIGER9）的活動派彩回調時，系統在這張表寫一筆紀錄，記下獲獎的局號、遊戲代碼、玩家帳號、派獎時間與獎勵金額。規則見相關規則。

## 相關規則

* [rules/vendor-target](../rules/vendor-target.md)
