---
type: Table
id: data-model/total_bet_records
title: 統一注單（TBR）
description: 'total_bet_records 是把各家遊戲商注單轉成同一組欄位後的注單，供跨遊戲商查詢與 SBR 彙總使用。'
domain: 遊戲與遊戲商
tags:
  - total_bet_records
  - TBR
  - 統一注單
  - SBR
  - 報表
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/TotalBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_total_bet_records_table.php
entity:
  table: total_bet_records
  model: App\Models\TotalBetRecord
  columns:
    - { name: id, type: 'bigint unsigned', nullable: false, comment: 流水號 }
    - { name: summary_time, type: datetime, nullable: false, comment: 時間 }
    - { name: member_id, type: int, nullable: false, comment: 會員系統編號 }
    - { name: vendor_id, type: int, nullable: false, comment: 遊戲商系統編號 }
    - { name: bet_record_id, type: 'bigint unsigned', nullable: false, comment: XXX_bet_record.id }
    - { name: game_id, type: int, nullable: false, comment: 遊戲系統編號 }
    - { name: reference_no, type: varchar(100), nullable: false, comment: 來源單號 }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 投注金額 }
    - { name: valid_bet, type: 'decimal(18,4)', nullable: false, comment: 有效投注金額 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金額 }
    - { name: jackpot, type: 'decimal(18,4)', nullable: false, comment: 彩金 }
    - { name: bonus, type: 'decimal(18,4)', nullable: false, comment: 活動獎金 }
    - { name: commission, type: 'decimal(18,4)', nullable: false, comment: 佣金 }
    - { name: rolling_chip, type: 'decimal(18,4)', nullable: false, comment: 禮碼 }
    - { name: valid_bet_rate, type: 'decimal(18,4)', nullable: false, comment: 有效投注比例 }
    - { name: bak, type: 'decimal(18,4)', nullable: false, comment: 備用1 }
    - { name: bak1, type: 'decimal(18,4)', nullable: false, comment: 備用2 }
    - { name: bet_time, type: datetime, nullable: false, comment: 投注時間(UTC) }
    - { name: payout_time, type: datetime, nullable: false, comment: 派彩、結算時間(UTC) }
    - { name: created_at, type: datetime, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: datetime, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
  read_by:
    - App\Models\TotalBetRecord
  written_by:
    - App\Models\TotalBetRecord
---

<!-- knowledge:generated start -->
## 表

`total_bet_records`：存放所有遊戲商的投注記錄

Model：`App\Models\TotalBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | bigint unsigned | 否 | 流水號 |
| `summary_time` | datetime | 否 | 時間 |
| `member_id` | int | 否 | 會員系統編號 |
| `vendor_id` | int | 否 | 遊戲商系統編號 |
| `bet_record_id` | bigint unsigned | 否 | XXX_bet_record.id |
| `game_id` | int | 否 | 遊戲系統編號 |
| `reference_no` | varchar(100) | 否 | 來源單號 |
| `bet` | decimal(18,4) | 否 | 投注金額 |
| `valid_bet` | decimal(18,4) | 否 | 有效投注金額 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金額 |
| `jackpot` | decimal(18,4) | 否 | 彩金 |
| `bonus` | decimal(18,4) | 否 | 活動獎金 |
| `commission` | decimal(18,4) | 否 | 佣金 |
| `rolling_chip` | decimal(18,4) | 否 | 禮碼 |
| `valid_bet_rate` | decimal(18,4) | 否 | 有效投注比例 |
| `bak` | decimal(18,4) | 否 | 備用1 |
| `bak1` | decimal(18,4) | 否 | 備用2 |
| `bet_time` | datetime | 否 | 投注時間(UTC) |
| `payout_time` | datetime | 否 | 派彩、結算時間(UTC) |
| `created_at` | datetime | 否 | 建立日期 |
| `updated_at` | datetime | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
<!-- knowledge:generated end -->

## 做什麼

這張表是 TBR，把各家遊戲商自己的注單轉成同一組欄位後的版本，一列對回某家遊戲商自己注單表的一列。拉取遊戲商注單時會把拉到的注單轉寫一份到這裡，各家無縫錢包遊戲商的回調也會即時轉寫；SBR 彙總與跨遊戲商查注單時只讀這張表，不必逐家去湊。規則見相關規則。

## 相關規則

* [rules/bet-record-pull](../rules/bet-record-pull.md)
* [rules/report-sr12-payout](../rules/report-sr12-payout.md)
