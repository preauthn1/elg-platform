---
type: Table
id: data-model/pgssw_bet_records
title: 'target 注單'
description: 'pgssw_bet_records 是無縫錢包遊戲商 target（含 BWG、TIGER9）回報進來、未經轉換的注單（BR），由 target 回調流程寫入。'
domain: 遊戲與遊戲商
tags:
  - pgssw_bet_records
  - target
  - PGSSW
  - BWG
  - TIGER9
  - BR
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/PgsswBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_pgssw_bet_records_table.php
entity:
  table: pgssw_bet_records
  model: App\Vendors\Models\PgsswBetRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: vendor_info_id, type: int, nullable: false, comment: 遊戲商設定編號 }
    - { name: bet_id, type: varchar(191), nullable: false, comment: '注单唯一Id, ThemeId' }
    - { name: session_token, type: varchar(50), nullable: false, comment: 訂單號的session_token }
    - { name: username, type: varchar(30), nullable: false, comment: 玩家帳號 }
    - { name: game_code, type: varchar(191), nullable: false, comment: 游戏ID }
    - { name: bet_time, type: datetime, nullable: false, comment: 投注時間(utc) }
    - { name: payout_time, type: datetime, nullable: false, comment: 派彩時間(utc) }
    - { name: settlement_time, type: datetime, nullable: false, comment: 結算時間 }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 下注金额 }
    - { name: win, type: 'decimal(18,4)', nullable: false, comment: 派彩金额 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金额 }
    - { name: rolling_chip, type: 'decimal(18,4)', nullable: false, comment: 禮碼金額 }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:未結算,1:已結算' }
    - { name: info, type: text, nullable: false, comment: 'response data' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\PgsswBetRecord
  written_by:
    - App\Vendors\Models\PgsswBetRecord
---

<!-- knowledge:generated start -->
## 表

`pgssw_bet_records`

Model：`App\Vendors\Models\PgsswBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `vendor_info_id` | int | 否 | 遊戲商設定編號 |
| `bet_id` | varchar(191) | 否 | 注单唯一Id, ThemeId |
| `session_token` | varchar(50) | 否 | 訂單號的session_token |
| `username` | varchar(30) | 否 | 玩家帳號 |
| `game_code` | varchar(191) | 否 | 游戏ID |
| `bet_time` | datetime | 否 | 投注時間(utc) |
| `payout_time` | datetime | 否 | 派彩時間(utc) |
| `settlement_time` | datetime | 否 | 結算時間 |
| `bet` | decimal(18,4) | 否 | 下注金额 |
| `win` | decimal(18,4) | 否 | 派彩金额 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金额 |
| `rolling_chip` | decimal(18,4) | 否 | 禮碼金額 |
| `status` | tinyint | 否 | 狀態 0:未結算,1:已結算 |
| `info` | text | 否 | response data |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表玩家在無縫錢包遊戲商 target（含 BWG、TIGER9 等子商）的一筆注單，欄位是 target 自己的格式，尚未轉成跨遊戲商共用的 TBR 欄位。玩家下注或結算時由 target 的回調流程寫入或更新這張表的列，再轉成 TBR；取消投注與活動派彩分別記在 [data-model/pgssw_cancel_logs](pgssw_cancel_logs.md)、[data-model/pgssw_event_records](pgssw_event_records.md)。規則見相關規則。

## 相關規則

* [rules/vendor-target](../rules/vendor-target.md)
