---
type: Table
id: data-model/afbsw_bet_records
title: 'AFBSW 注單'
description: 'afbsw_bet_records 是無縫錢包遊戲商 AFBSW 回報進來、未經轉換的注單（BR），由 AFBSwController 的回調寫入。'
domain: 遊戲與遊戲商
tags:
  - afbsw_bet_records
  - AFBSW
  - BR
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/AfbswBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2026_01_20_091946_create_afbsw_bet_records_table.php
entity:
  table: afbsw_bet_records
  model: App\Vendors\Models\AfbswBetRecord
  columns:
    - { name: id, type: 'bigint unsigned', nullable: false }
    - { name: member_id, type: int, nullable: false, comment: 會員編號 }
    - { name: username, type: varchar(50), nullable: false, comment: '玩家帳號 playerId' }
    - { name: bet_id, type: varchar(50), nullable: false, comment: '注單號 betId' }
    - { name: game_code, type: varchar(50), nullable: false, comment: 遊戲ID }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 投注額 }
    - { name: valid_bet, type: 'decimal(18,4)', nullable: false, comment: 有效投注額 }
    - { name: payout, type: 'decimal(18,4)', nullable: false, comment: 派彩金額 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金額 }
    - { name: rolling_chips, type: 'decimal(18,4)', nullable: false, comment: 投注禮碼金額 }
    - { name: bet_time, type: timestamp, nullable: false, comment: 投注時間 }
    - { name: payout_time, type: timestamp, nullable: true, comment: 結算時間 }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:建立注單,1:已結算' }
    - { name: info, type: json, nullable: true, comment: 注單信息 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\AfbswBetRecord
  written_by:
    - App\Vendors\Models\AfbswBetRecord
---

<!-- knowledge:generated start -->
## 表

`afbsw_bet_records`

Model：`App\Vendors\Models\AfbswBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | bigint unsigned | 否 |  |
| `member_id` | int | 否 | 會員編號 |
| `username` | varchar(50) | 否 | 玩家帳號 playerId |
| `bet_id` | varchar(50) | 否 | 注單號 betId |
| `game_code` | varchar(50) | 否 | 遊戲ID |
| `bet` | decimal(18,4) | 否 | 投注額 |
| `valid_bet` | decimal(18,4) | 否 | 有效投注額 |
| `payout` | decimal(18,4) | 否 | 派彩金額 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金額 |
| `rolling_chips` | decimal(18,4) | 否 | 投注禮碼金額 |
| `bet_time` | timestamp | 否 | 投注時間 |
| `payout_time` | timestamp | 是 | 結算時間 |
| `status` | tinyint | 否 | 狀態 0:建立注單,1:已結算 |
| `info` | json | 是 | 注單信息 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表玩家在無縫錢包遊戲商 AFBSW 的一筆注單，欄位是 AFBSW 自己的格式，尚未轉成跨遊戲商共用的 TBR 欄位。玩家下注或結算時，AFBSW 回調 `AFBSwController`，由它寫入或更新這張表的列，再轉成 TBR；注單被 AFBSW 取消投注後，這張表的 `status` 會記成已退款，殘留的投注量要靠 `bet-record-recovery:zero-refunded-turnover` 命令歸零。規則見相關規則。

## 相關規則

* [rules/vendor-afbsw](../rules/vendor-afbsw.md)
