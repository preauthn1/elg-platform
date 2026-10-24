---
type: Table
id: data-model/acewin_bet_records
title: 'Acewin 注單'
description: 'acewin_bet_records 是無縫錢包遊戲商 Acewin 回報進來、未經轉換的注單（BR），由 AcewinController 的回調寫入。'
domain: 遊戲與遊戲商
tags:
  - acewin_bet_records
  - Acewin
  - BR
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/AcewinBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_acewin_bet_records_table.php
entity:
  table: acewin_bet_records
  model: App\Vendors\Models\AcewinBetRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: vendor_info_id, type: int, nullable: false, comment: 遊戲商設定編號 }
    - { name: bet_id, type: varchar(191), nullable: false, comment: '注單唯一Id, ThemeId' }
    - { name: vendor_username, type: varchar(191), nullable: false, comment: 遊戲商的玩家帳號名 }
    - { name: bet_time, type: datetime, nullable: false, comment: 投注時間(UTC) }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 下注金額 }
    - { name: valid_bet, type: 'decimal(18,4)', nullable: false, comment: 有效投注金額 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金額 }
    - { name: payout_time, type: datetime, nullable: true, comment: 派彩、結算時間(UTC) }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:未結算,1:已結算' }
    - { name: game_code, type: varchar(191), nullable: false, comment: '遊戲 ID-依據遊戲商需求' }
    - { name: rolling_chip, type: 'decimal(18,4)', nullable: false, comment: 禮碼金額-依據遊戲商需求 }
    - { name: jackpot, type: 'decimal(18,4)', nullable: false, comment: 派彩金额-依據遊戲商需求 }
    - { name: session_token, type: varchar(191), nullable: false, comment: '訪問投注單的 Session Token 或 ID 簽章-依據遊戲商需求' }
    - { name: info, type: longtext, nullable: false, comment: 'response data' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\AcewinBetRecord
  written_by:
    - App\Vendors\Models\AcewinBetRecord
---

<!-- knowledge:generated start -->
## 表

`acewin_bet_records`

Model：`App\Vendors\Models\AcewinBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `vendor_info_id` | int | 否 | 遊戲商設定編號 |
| `bet_id` | varchar(191) | 否 | 注單唯一Id, ThemeId |
| `vendor_username` | varchar(191) | 否 | 遊戲商的玩家帳號名 |
| `bet_time` | datetime | 否 | 投注時間(UTC) |
| `bet` | decimal(18,4) | 否 | 下注金額 |
| `valid_bet` | decimal(18,4) | 否 | 有效投注金額 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金額 |
| `payout_time` | datetime | 是 | 派彩、結算時間(UTC) |
| `status` | tinyint | 否 | 狀態 0:未結算,1:已結算 |
| `game_code` | varchar(191) | 否 | 遊戲 ID-依據遊戲商需求 |
| `rolling_chip` | decimal(18,4) | 否 | 禮碼金額-依據遊戲商需求 |
| `jackpot` | decimal(18,4) | 否 | 派彩金额-依據遊戲商需求 |
| `session_token` | varchar(191) | 否 | 訪問投注單的 Session Token 或 ID 簽章-依據遊戲商需求 |
| `info` | longtext | 否 | response data |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表玩家在無縫錢包遊戲商 Acewin 的一筆注單，欄位是 Acewin 自己的格式，尚未轉成跨遊戲商共用的 TBR 欄位。玩家下注、取消或結算時，Acewin 回調 `AcewinController`，由它寫入或更新這張表的列，再轉成 TBR。規則見相關規則。

## 相關規則

* [rules/vendor-acewin](../rules/vendor-acewin.md)
