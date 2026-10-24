---
type: Table
id: data-model/pgssw_cancel_logs
title: 'target 取消投注紀錄'
description: 'pgssw_cancel_logs 記錄 target（含 BWG、TIGER9）取消投注回調的處理狀態，由 target 回調流程讀寫。'
domain: 遊戲與遊戲商
tags:
  - pgssw_cancel_logs
  - target
  - PGSSW
  - BWG
  - TIGER9
  - 取消投注
  - 無縫錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/PgsswCancelLog.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_pgssw_cancel_logs_table.php
entity:
  table: pgssw_cancel_logs
  model: App\Vendors\Models\PgsswCancelLog
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: username, type: varchar(50), nullable: false, comment: pgssw_accounts.username }
    - { name: transaction_no, type: varchar(50), nullable: false, comment: transactionNo }
    - { name: game_id, type: int, nullable: false, comment: 投注游戏 }
    - { name: status, type: tinyint, nullable: false, comment: '處理狀態 0:待處理,1:成功,2:失敗' }
    - { name: response_data, type: text, nullable: true, comment: 'response data' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Vendors\Models\PgsswCancelLog
  written_by:
    - App\Vendors\Models\PgsswCancelLog
---

<!-- knowledge:generated start -->
## 表

`pgssw_cancel_logs`

Model：`App\Vendors\Models\PgsswCancelLog`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `username` | varchar(50) | 否 | pgssw_accounts.username |
| `transaction_no` | varchar(50) | 否 | transactionNo |
| `game_id` | int | 否 | 投注游戏 |
| `status` | tinyint | 否 | 處理狀態 0:待處理,1:成功,2:失敗 |
| `response_data` | text | 是 | response data |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

target（程式裡叫 PGSSW，含經它接入的 BWG 與 TIGER9）收到取消投注回調時，系統先依交易編號在這張表建一筆取消紀錄；找到對應注單並完成退款後，才把這筆紀錄標記為已處理，還沒找到注單的紀錄留在待處理狀態。規則見相關規則。

## 相關規則

* [rules/vendor-target](../rules/vendor-target.md)
