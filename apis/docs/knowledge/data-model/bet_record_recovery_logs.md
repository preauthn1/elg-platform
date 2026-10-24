---
type: Table
id: data-model/bet_record_recovery_logs
title: 注單人工補帳撤回事件記錄
description: 'bet_record_recovery_logs 記錄維運用命令對無縫錢包注單做人工補帳（退款、投注量歸零）或撤回補帳的每一次操作，供日後查核。'
domain: 遊戲與遊戲商
tags:
  - bet_record_recovery_logs
  - 補帳作業
  - 稽核紀錄
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/BetRecordRecoveryLog.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2026_05_06_000001_create_bet_record_recovery_logs_table.php
entity:
  table: bet_record_recovery_logs
  model: App\Models\BetRecordRecoveryLog
  columns:
    - { name: id, type: 'bigint unsigned', nullable: false }
    - { name: vendor_id, type: int, nullable: false, comment: 遊戲商系統編號 }
    - { name: bet_record_id, type: 'bigint unsigned', nullable: false, comment: '來源注單表 id（依 vendor_id 對應 XXX_bet_records）' }
    - { name: bet_id, type: varchar(255), nullable: false, comment: '注單 BetId（冗余便於跨 vendor 追查）' }
    - { name: member_id, type: int, nullable: false, comment: 會員系統編號 }
    - { name: type, type: varchar(20), nullable: false, comment: 事件類型：recover=補帳、revert=撤回補帳 }
    - { name: performed_at, type: timestamp, nullable: false, comment: 執行時間 }
    - { name: performed_by, type: varchar(100), nullable: false, comment: '執行者：command 名稱 / 客服姓名' }
    - { name: reason, type: text, nullable: false, comment: 執行原因（必填） }
    - { name: source, type: varchar(200), nullable: true, comment: '資料來源：CSV 檔名:row、客服票號等' }
    - { name: before, type: json, nullable: false, comment: 操作前快照 }
    - { name: after, type: json, nullable: false, comment: 操作後快照 }
    - { name: balance_diff, type: 'decimal(18,4)', nullable: false, comment: 玩家錢包淨變動：recover=正、revert=負 }
    - { name: parent_log_id, type: 'bigint unsigned', nullable: true, comment: '被撤回的 recover log id；type=revert 時填，type=recover 時為 NULL；MySQL UNIQUE 允許多 NULL，故同一注單可多次補帳，但每筆 recover 最多一次撤回' }
    - { name: created_at, type: timestamp, nullable: true }
    - { name: updated_at, type: timestamp, nullable: true }
  read_by:
    - App\Models\BetRecordRecoveryLog
  written_by:
    - App\Models\BetRecordRecoveryLog
---

<!-- knowledge:generated start -->
## 表

`bet_record_recovery_logs`：注單人工補帳/撤回事件記錄（跨遊戲商通用，event-log 模式）

Model：`App\Models\BetRecordRecoveryLog`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | bigint unsigned | 否 |  |
| `vendor_id` | int | 否 | 遊戲商系統編號 |
| `bet_record_id` | bigint unsigned | 否 | 來源注單表 id（依 vendor_id 對應 XXX_bet_records） |
| `bet_id` | varchar(255) | 否 | 注單 BetId（冗余便於跨 vendor 追查） |
| `member_id` | int | 否 | 會員系統編號 |
| `type` | varchar(20) | 否 | 事件類型：recover=補帳、revert=撤回補帳 |
| `performed_at` | timestamp | 否 | 執行時間 |
| `performed_by` | varchar(100) | 否 | 執行者：command 名稱 / 客服姓名 |
| `reason` | text | 否 | 執行原因（必填） |
| `source` | varchar(200) | 是 | 資料來源：CSV 檔名:row、客服票號等 |
| `before` | json | 否 | 操作前快照 |
| `after` | json | 否 | 操作後快照 |
| `balance_diff` | decimal(18,4) | 否 | 玩家錢包淨變動：recover=正、revert=負 |
| `parent_log_id` | bigint unsigned | 是 | 被撤回的 recover log id；type=revert 時填，type=recover 時為 NULL；MySQL UNIQUE 允許多 NULL，故同一注單可多次補帳，但每筆 recover 最多一次撤回 |
| `created_at` | timestamp | 是 |  |
| `updated_at` | timestamp | 是 |  |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表一次人工補帳作業：維運跑 `bet-record-recovery:refund-unsettled` 把未結算注單的投注額退回玩家，或跑 `bet-record-recovery:zero-refunded-turnover` 把已退款注單殘留的投注量歸零時，各寫入一筆 `type` 為 `recover` 的紀錄，含操作前後快照與玩家錢包的淨變動金額；同一筆 `recover` 最多能被撤回一次，撤回時另寫一筆 `type` 為 `revert` 的紀錄並透過 `parent_log_id` 指回原紀錄。這張表同時也是防止同一筆注單重複補帳的依據。規則見相關規則。

## 相關規則

* [rules/single-wallet-unsettled-refund](../rules/single-wallet-unsettled-refund.md)
