---
type: Table
id: data-model/onebxsw_bet_records
title: 'OnebxSW 注單'
description: 'onebxsw_bet_records 是無縫錢包遊戲商 OnebxSW 回報進來、未經轉換的注單（BR），由 OnebxSWController 的回調寫入。'
domain: 遊戲與遊戲商
tags:
  - onebxsw_bet_records
  - OnebxSW
  - BR
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/OnebxswBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_08_21_101108_create_table_onebxsw_bet_records.php
entity:
  table: onebxsw_bet_records
  model: App\Vendors\Models\OnebxswBetRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: bet_id, type: varchar(200), nullable: false, comment: 'bettransacion_id from request' }
    - { name: username, type: varchar(30), nullable: false, comment: 玩家帳號 }
    - { name: game_code, type: varchar(30), nullable: false, comment: 遊戲編號 }
    - { name: status, type: tinyint, nullable: false, comment: '0: 未有結果, -1: 取消, 9: 結算' }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 下注金額 }
    - { name: valid_bet, type: 'decimal(18,4)', nullable: false, comment: 真實下注金額 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金額 }
    - { name: rolling_chips, type: 'decimal(18,4)', nullable: false, comment: 使用禮碼 }
    - { name: bet_time, type: datetime, nullable: false, comment: 下注時間 }
    - { name: payout_time, type: datetime, nullable: false, comment: 派彩/取消/bonus時間 }
    - { name: infos, type: json, nullable: true, comment: 詳細資訊 }
    - { name: created_at, type: timestamp, nullable: false }
    - { name: updated_at, type: timestamp, nullable: false }
  read_by:
    - App\Vendors\Models\OnebxswBetRecord
  written_by:
    - App\Vendors\Models\OnebxswBetRecord
---

<!-- knowledge:generated start -->
## 表

`onebxsw_bet_records`

Model：`App\Vendors\Models\OnebxswBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `bet_id` | varchar(200) | 否 | bettransacion_id from request |
| `username` | varchar(30) | 否 | 玩家帳號 |
| `game_code` | varchar(30) | 否 | 遊戲編號 |
| `status` | tinyint | 否 | 0: 未有結果, -1: 取消, 9: 結算 |
| `bet` | decimal(18,4) | 否 | 下注金額 |
| `valid_bet` | decimal(18,4) | 否 | 真實下注金額 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金額 |
| `rolling_chips` | decimal(18,4) | 否 | 使用禮碼 |
| `bet_time` | datetime | 否 | 下注時間 |
| `payout_time` | datetime | 否 | 派彩/取消/bonus時間 |
| `infos` | json | 是 | 詳細資訊 |
| `created_at` | timestamp | 否 |  |
| `updated_at` | timestamp | 否 |  |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表玩家在無縫錢包遊戲商 OnebxSW 的一筆注單，欄位是 OnebxSW 自己的格式，尚未轉成跨遊戲商共用的 TBR 欄位。玩家下注、取消或結算（含 bonus）時，OnebxSW 回調 `OnebxSWController`，由它寫入或更新這張表的列，再轉成 TBR。規則見相關規則。

## 相關規則

* [rules/vendor-onebxsw](../rules/vendor-onebxsw.md)
