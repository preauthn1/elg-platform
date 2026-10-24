---
type: Table
id: data-model/summary_bet_records
title: 注單彙總（SBR）
description: 'summary_bet_records 是依每人每小時每款遊戲聚合後的投注結算數字，報表與代理分潤讀它。'
domain: 遊戲與遊戲商
tags:
  - summary_bet_records
  - SBR
  - 注單彙總
  - TBR
  - 小時桶
  - 調帳差額
  - 報表
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/SummaryBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_summary_bet_records_table.php
entity:
  table: summary_bet_records
  model: App\Models\SummaryBetRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: summary_time, type: datetime, nullable: false, comment: 時間 }
    - { name: data_time, type: datetime, nullable: false, comment: 數據時間 }
    - { name: member_id, type: int, nullable: false, comment: 會員系統編號 }
    - { name: vendor_id, type: int, nullable: false, comment: 遊戲商系統編號 }
    - { name: game_id, type: int, nullable: false, comment: 遊戲系統編號 }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 投注金額 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 贏得金額 }
    - { name: rolling_chip, type: 'decimal(18,4)', nullable: false, comment: 禮碼 }
    - { name: commission, type: 'decimal(18,4)', nullable: true, comment: 佣金 }
    - { name: jackpot, type: 'decimal(18,4)', nullable: false, comment: 彩金 }
    - { name: record_count, type: int, nullable: false, comment: 資料計數 }
    - { name: bak, type: 'decimal(18,4)', nullable: false, comment: 備用 }
    - { name: bak1, type: 'decimal(18,4)', nullable: false, comment: 備用 }
    - { name: percentage, type: 'decimal(8,2)', nullable: true, comment: 分潤比例 }
    - { name: share_amount, type: 'decimal(8,2)', nullable: true, comment: 分潤金額 }
    - { name: share_range, type: varchar(191), nullable: true, comment: 分成區間 }
    - { name: v_jackpot, type: 'decimal(18,4)', nullable: false, comment: v_jackpot }
    - { name: cost, type: 'decimal(18,4)', nullable: false, comment: 成本 }
    - { name: correction, type: tinyint, nullable: false, comment: 改正標示 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
  read_by:
    - App\Models\SummaryBetRecord
  written_by:
    - App\Models\SummaryBetRecord
---

<!-- knowledge:generated start -->
## 表

`summary_bet_records`

Model：`App\Models\SummaryBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `summary_time` | datetime | 否 | 時間 |
| `data_time` | datetime | 否 | 數據時間 |
| `member_id` | int | 否 | 會員系統編號 |
| `vendor_id` | int | 否 | 遊戲商系統編號 |
| `game_id` | int | 否 | 遊戲系統編號 |
| `bet` | decimal(18,4) | 否 | 投注金額 |
| `win_lose` | decimal(18,4) | 否 | 贏得金額 |
| `rolling_chip` | decimal(18,4) | 否 | 禮碼 |
| `commission` | decimal(18,4) | 是 | 佣金 |
| `jackpot` | decimal(18,4) | 否 | 彩金 |
| `record_count` | int | 否 | 資料計數 |
| `bak` | decimal(18,4) | 否 | 備用 |
| `bak1` | decimal(18,4) | 否 | 備用 |
| `percentage` | decimal(8,2) | 是 | 分潤比例 |
| `share_amount` | decimal(8,2) | 是 | 分潤金額 |
| `share_range` | varchar(191) | 是 | 分成區間 |
| `v_jackpot` | decimal(18,4) | 否 | v_jackpot |
| `cost` | decimal(18,4) | 否 | 成本 |
| `correction` | tinyint | 否 | 改正標示 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
<!-- knowledge:generated end -->

## 做什麼

這張表是 SBR（把 TBR，也就是各家注單轉成同一組欄位之後的注單，依每人每小時每款遊戲聚合後的結算數字），一列代表某個會員在某個小時桶、某家遊戲商、某款遊戲的投注、輸贏與分潤彙總。整點排程、人工補跑命令與 Olympus「重跑注單彙總」頁寫入這張表，報表與代理分潤讀它；`correction` 欄標示這一列是不是已關帳後補上的調帳差額列。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../rules/summary-bet-record.md)
